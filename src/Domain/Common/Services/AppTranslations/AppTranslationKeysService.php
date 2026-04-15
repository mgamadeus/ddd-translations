<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\AppTranslations;

use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Entities\QueryOptions\FiltersOptions;
use DDD\Domain\Base\Repo\DatabaseRepoEntity;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationKey;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationKeys;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationValue;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationValues;
use DDD\Domain\Common\Entities\AppTranslations\Completeness\AppTranslationCompleteness;
use DDD\Domain\Common\Entities\AppTranslations\Completeness\AppTranslationCompletenesses;
use DDD\Domain\Common\Entities\Languages\Language;
use DDD\Domain\Common\Entities\Locales\Locale;
use DDD\Domain\Common\Entities\Locales\Locales;
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationKey;
use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationKeys;
use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationValue;
use DDD\Domain\Common\Services\Languages\LanguagesService;
use DDD\Infrastructure\Libs\Config;
use Throwable;

/**
 * Service for managing AppTranslationKey entities
 *
 * @method AppTranslationKey find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AppTranslationKeys findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method AppTranslationKey update(DefaultObject $entity)
 * @method DBAppTranslationKey getEntityRepoClassInstance()
 * @method DBAppTranslationKeys getEntitySetRepoClassInstance()
 */
class AppTranslationKeysService extends EntitiesService
{
    public const string DEFAULT_ENTITY_CLASS = AppTranslationKey::class;

    /**
     * Find AppTranslationKeys by an array of key strings
     *
     * @param array $keyStrings Array of key strings to find
     * @return AppTranslationKeys
     */
    public function findByKeyStrings(array $keyStrings): AppTranslationKeys
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$baseModelAlias}.key IN (:keyStrings)");
        $queryBuilder->setParameter('keyStrings', $keyStrings);

        return $repoClass->find($queryBuilder);
    }

    /**
     * Find untranslated AppTranslationKeys for a given language ID.
     * Returns keys that have no translations matching the given criteria.
     *
     * @param int $languageId The language ID to check
     * @param int|null $limit Maximum number of keys to return
     * @param int|null $minAppTranslationKeyId Minimum key ID to start from
     * @param bool $randomOrder Whether to randomize the order
     * @param bool $ignoreKeysThatShouldNotBeTranslatedAutomatically Whether to skip doNotTranslateAutomatically keys
     * @param string|null $writingStyle Filter by writing style
     * @return AppTranslationKeys
     */
    public function findUntranslatedKeysForLanguageId(
        int $languageId,
        ?int $limit = 50,
        ?int $minAppTranslationKeyId = null,
        bool $randomOrder = false,
        bool $ignoreKeysThatShouldNotBeTranslatedAutomatically = true,
        ?string $writingStyle = null,
    ): AppTranslationKeys {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();

        // Build subquery to find keys that already have translations
        $valueOrmModel = DBAppTranslationValue::BASE_ORM_MODEL;
        $subQuery = "SELECT atvSub.appTranslationKeyId FROM {$valueOrmModel} atvSub WHERE atvSub.languageId = :languageId";

        if ($writingStyle) {
            $subQuery .= ' AND atvSub.writingStyle = :writingStyle';
            $queryBuilder->setParameter('writingStyle', $writingStyle);
        }

        $queryBuilder->andWhere("{$baseModelAlias}.id NOT IN ({$subQuery})");
        $queryBuilder->setParameter('languageId', $languageId);

        if ($ignoreKeysThatShouldNotBeTranslatedAutomatically) {
            $queryBuilder->andWhere("{$baseModelAlias}.doNotTranslateAutomatically = :doNotTranslate");
            $queryBuilder->setParameter('doNotTranslate', false);
        }

        if ($minAppTranslationKeyId) {
            $queryBuilder->andWhere("{$baseModelAlias}.id >= :minId");
            $queryBuilder->setParameter('minId', $minAppTranslationKeyId);
        }

        if ($randomOrder) {
            $queryBuilder->orderBy('RAND()');
        }

        if ($limit) {
            $queryBuilder->setMaxResults($limit);
        }

        return $repoClass->find($queryBuilder);
    }

    /**
     * Find keys that are marked for re-translation for a given language ID
     *
     * @param int $languageId The language ID to check
     * @param int|null $limit Maximum number of keys to return
     * @param string|null $writingStyle Filter by writing style
     * @return AppTranslationKeys
     */
    public function findReTranslateSetKeysForLanguageId(
        int $languageId,
        ?int $limit = null,
        ?string $writingStyle = null,
    ): AppTranslationKeys {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();

        $queryBuilder->andWhere("{$baseModelAlias}.reTranslate = :reTranslate");
        $queryBuilder->setParameter('reTranslate', true);

        if ($limit) {
            $queryBuilder->setMaxResults($limit);
        }

        return $repoClass->find($queryBuilder);
    }

    /**
     * Returns all keys that use translation templates
     *
     * @return AppTranslationKeys
     */
    public function findKeysUsingTranslationTemplates(): AppTranslationKeys
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();

        $queryBuilder->andWhere("{$baseModelAlias}.translationTemplate IS NOT NULL");
        $queryBuilder->andWhere("{$baseModelAlias}.translationTemplate != :emptyString");
        $queryBuilder->setParameter('emptyString', '');

        return $repoClass->find($queryBuilder);
    }

    /**
     * Returns the translation completeness status for all active Locales.
     * For each active Locale, counts how many keys have been translated per writing style (INFORMAL / FORMAL)
     * and compares against the total number of keys.
     *
     * @return AppTranslationCompletenesses
     */
    public function getCompletenessForActiveLocales(): AppTranslationCompletenesses
    {
        // Use QueryOptions to filter only active locales
        $defaultQueryOptions = clone Locales::getDefaultQueryOptions();
        $filtersOptions = FiltersOptions::fromString("isActive eq '1'");
        Locales::getDefaultQueryOptions()->setFilters($filtersOptions);

        $localesService = Locale::getService();
        $activeLocales = $localesService->findAll();

        // Restore original QueryOptions
        Locales::setDefaultQueryOptions($defaultQueryOptions);

        $totalKeys = $this->countAll();

        /** @var AppTranslationValuesService $valuesService */
        $valuesService = AppTranslationValue::getService();

        $completenesses = new AppTranslationCompletenesses();
        $originalValuesQueryOptions = clone AppTranslationValues::getDefaultQueryOptions();

        foreach ($activeLocales->getElements() as $locale) {
            $baseFilter = "languageId eq '{$locale->languageId}' AND virtualCountryId eq '0'";

            // Count informal translations
            $informalFilter = FiltersOptions::fromString(
                $baseFilter . " AND writingStyle eq '" . Text::WRITING_STYLE_INFORMAL . "'"
            );
            AppTranslationValues::getDefaultQueryOptions()->setFilters($informalFilter);
            $informalCount = $valuesService->countAll();

            // Count formal translations
            $formalFilter = FiltersOptions::fromString(
                $baseFilter . " AND writingStyle eq '" . Text::WRITING_STYLE_FORMAL . "'"
            );
            AppTranslationValues::getDefaultQueryOptions()->setFilters($formalFilter);
            $formalCount = $valuesService->countAll();

            $completeness = new AppTranslationCompleteness(
                locale: $locale, totalKeys: $totalKeys, informalTranslatedCount: $informalCount, formalTranslatedCount: $formalCount,
            );

            $completenesses->add($completeness);
        }

        // Restore original QueryOptions
        AppTranslationValues::setDefaultQueryOptions($originalValuesQueryOptions);

        return $completenesses;
    }

    /**
     * Imports AppTranslationKeys and AppTranslationValues from config/app/Common/Translations.php.
     *
     * Config format: ['key string' => ['languageCode::FORMAL' => 'translated value', ...], ...]
     * Values in config are declared as FORMAL but are imported as INFORMAL writing style.
     *
     * For each entry:
     * - Creates or finds the AppTranslationKey by key string
     * - For each locale value, resolves languageCode → languageId via LanguagesService
     * - Creates AppTranslationValue with writingStyle = INFORMAL (skips if already exists)
     *
     * @return array{keysCreated: int, keysExisting: int, valuesCreated: int, valuesSkipped: int, errors: string[]}
     */
    public function importFromTranslationsConfig(): array
    {
        $config = Config::get('Common.Translations');
        if (!is_array($config) || empty($config)) {
            return ['keysCreated' => 0, 'keysExisting' => 0, 'valuesCreated' => 0, 'valuesSkipped' => 0, 'errors' => ['Config not found or empty']];
        }

        $previousRights = DatabaseRepoEntity::getApplyRightsRestrictions();
        DatabaseRepoEntity::setApplyRightsRestrictions(false);

        /** @var LanguagesService $languagesService */
        $languagesService = Language::getService();

        // Pre-load language map: languageCode → languageId
        $languageMap = [];

        $keysCreated = 0;
        $keysExisting = 0;
        $valuesCreated = 0;
        $valuesSkipped = 0;
        $errors = [];

        /** @var AppTranslationValuesService $valuesService */
        $valuesService = AppTranslationValue::getService();

        foreach ($config as $keyString => $translations) {
            if (!is_string($keyString) || !is_array($translations)) {
                continue;
            }

            // Find or create the AppTranslationKey
            $appTranslationKey = $this->findByKeyString($keyString);
            if ($appTranslationKey) {
                $keysExisting++;
            } else {
                $appTranslationKey = new AppTranslationKey();
                $appTranslationKey->key = $keyString;
                try {
                    $appTranslationKey = $this->update($appTranslationKey);
                    $keysCreated++;
                } catch (Throwable $t) {
                    $errors[] = "Failed to create key '{$keyString}': " . $t->getMessage();
                    continue;
                }
            }

            // Import each translation value
            foreach ($translations as $localeKey => $translatedText) {
                if (!is_string($localeKey) || !is_string($translatedText)) {
                    continue;
                }

                // Parse languageCode from "languageCode::FORMAL"
                $parts = explode('::', $localeKey);
                $languageCode = $parts[0] ?? null;
                if (!$languageCode) {
                    continue;
                }

                // Resolve languageId (cached)
                if (!isset($languageMap[$languageCode])) {
                    $language = $languagesService->findByLanguageCode($languageCode);
                    $languageMap[$languageCode] = $language?->id;
                }
                $languageId = $languageMap[$languageCode] ?? null;
                if (!$languageId) {
                    $errors[] = "Language not found for code '{$languageCode}' (key: '{$keyString}')";
                    continue;
                }

                // Check if value already exists for this key + language + INFORMAL
                $existing = $valuesService->findByKeyLanguageAndWritingStyle(
                    (int)$appTranslationKey->id,
                    $languageId,
                    Text::WRITING_STYLE_INFORMAL
                );

                if ($existing) {
                    $valuesSkipped++;
                    continue;
                }

                // Create new AppTranslationValue with INFORMAL writing style
                $value = new AppTranslationValue();
                $value->appTranslationKeyId = (int)$appTranslationKey->id;
                $value->languageId = $languageId;
                $value->translation = $translatedText;
                $value->writingStyle = Text::WRITING_STYLE_INFORMAL;

                try {
                    $value->update();
                    $valuesCreated++;
                } catch (Throwable $t) {
                    $errors[] = "Failed to create value for key '{$keyString}', lang '{$languageCode}': " . $t->getMessage();
                }
            }
        }

        DatabaseRepoEntity::setApplyRightsRestrictions($previousRights);

        return [
            'keysCreated' => $keysCreated,
            'keysExisting' => $keysExisting,
            'valuesCreated' => $valuesCreated,
            'valuesSkipped' => $valuesSkipped,
            'errors' => $errors,
        ];
    }

    /**
     * Find an AppTranslationKey by its key string
     *
     * @param string $keyString The translation key string
     * @return AppTranslationKey|null
     */
    public function findByKeyString(string $keyString): ?AppTranslationKey
    {
        $repoClass = $this->getEntityRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();
        $queryBuilder->andWhere("{$baseModelAlias}.key = :keyString");
        $queryBuilder->setParameter('keyString', $keyString);

        return $repoClass->find($queryBuilder);
    }
}
