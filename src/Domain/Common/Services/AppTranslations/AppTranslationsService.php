<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\AppTranslations;

use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Services\AIModelsService;
use DDD\Domain\Common\Entities\Texts\TranslationPrompts;
use DDD\Domain\AI\Services\AIPromptsService;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperations;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationDefaultTerms;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationKey;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationKeys;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationsResult;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationsResults;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationValue;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationValues;
use DDD\Domain\Common\Entities\Languages\Language;
use DDD\Domain\Common\Entities\Locales\Locale;
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Common\Entities\Texts\Texts;
use DDD\Domain\Common\Entities\Texts\Translations\Translations;
use DDD\Domain\Common\MessageHandlers\AppTranslationsMessage;
use DDD\Domain\Common\Repo\Argus\Texts\ArgusTexts;
use DDD\Domain\Common\Repo\Argus\Texts\Translations\ArgusTranslations;
use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationDefaultTerms;
use DDD\Domain\Common\Services\Languages\LanguagesService;
use DDD\Domain\Common\Services\Languages\LocalesService;
use DDD\Infrastructure\Exceptions\BadRequestException;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Exceptions\NotFoundException;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Libs\Datafilter;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Services\Service;
use Psr\Cache\InvalidArgumentException;
use ReflectionException;

class AppTranslationsService extends Service
{
    /**
     * Page size for cursor-paginated iteration over untranslated keys in
     * {@see self::generateAppTranslationsForLocale()}. Each page is dispatched as one or more
     * async translation jobs while the next page is being fetched, so total wall time is
     * dominated by Argus throughput rather than the sequential DB round-trips.
     */
    public const int APP_TRANSLATIONS_KEYS_PAGE_SIZE = 200;

    /**
     * Hard upper bound on cursor-pagination iterations in {@see self::generateAppTranslationsForLocale()}.
     * At PAGE_SIZE=200 this covers 20 million keys — orders of magnitude above any realistic
     * app translation table. Acts as a defensive guard so a misbehaving cursor (DB returning
     * rows that don't match the id-filter, identity-map glitches, transient entities without
     * ids) can never spin the loop forever.
     */
    protected const int APP_TRANSLATIONS_MAX_ITERATIONS = 100_000;

    /**
     * Returns an array with the most often used words in AppTranslationKeys
     *
     * @return array
     */
    public function findMostOftenUsedWordsInAppTranslationKeys(): array
    {
        /** @var AppTranslationKeysService $appTranslationKeysService */
        $appTranslationKeysService = DDDService::instance()->getService(AppTranslationKeysService::class);
        $appTranslationKeys = $appTranslationKeysService->findAll();
        $mostOftenUsedWords = [];
        $commonWords = Config::get('Common.AppTranslations.commonWords');
        foreach ($appTranslationKeys->getElements() as $appTranslationKey) {
            preg_match_all('/\b\w+\b/', $appTranslationKey->key, $words);
            $words = $words[0];
            foreach ($words as $word) {
                $word = Datafilter::clean_keyword($word);
                if (strlen($word) > 2 && !is_numeric($word) && !isset($commonWords[$word])) {
                    if (isset($mostOftenUsedWords[$word])) {
                        $mostOftenUsedWords[$word]++;
                    } else {
                        $mostOftenUsedWords[$word] = 1;
                    }
                }
            }
        }
        arsort($mostOftenUsedWords);
        return $mostOftenUsedWords;
    }

    /**
     * Returns default translation terms for a given language ID
     *
     * @param int $languageId
     * @return AppTranslationDefaultTerms
     */
    public function findDefaultTermsForLanguageId(int $languageId): AppTranslationDefaultTerms
    {
        $dbAppTranslationDefaultTerms = new DBAppTranslationDefaultTerms();
        $queryBuilder = $dbAppTranslationDefaultTerms::createQueryBuilder();
        $baseModelAlias = $dbAppTranslationDefaultTerms::getBaseModelAlias();
        $queryBuilder->andWhere("$baseModelAlias.languageId = :languageId");
        $queryBuilder->setParameter('languageId', $languageId);

        return $dbAppTranslationDefaultTerms->find($queryBuilder) ?? new AppTranslationDefaultTerms();
    }

    /**
     * Returns untranslated AppTranslationKeys for given language
     *
     * @param int $languageId
     * @param int|null $limit
     * @param int|null $minAppTranslationId
     * @param bool $randomOrder
     * @param bool $ignoreKeysThatShouldNotBeTranslatedAutomatically
     * @param bool $translateReTranslateSetKeys
     * @param string|null $writingStyle
     * @return AppTranslationKeys
     */
    public function findUntranslatedKeysForLanguage(
        int $languageId,
        ?int $limit = 50,
        ?int $minAppTranslationId = null,
        bool $randomOrder = false,
        bool $ignoreKeysThatShouldNotBeTranslatedAutomatically = true,
        bool $translateReTranslateSetKeys = false,
        ?string $writingStyle = null,
    ): AppTranslationKeys {
        /** @var AppTranslationKeysService $appTranslationKeysService */
        $appTranslationKeysService = DDDService::instance()->getService(AppTranslationKeysService::class);

        $untranslatedKeys = $appTranslationKeysService->findUntranslatedKeysForLanguageId(
            $languageId,
            $limit,
            $minAppTranslationId,
            $randomOrder,
            $ignoreKeysThatShouldNotBeTranslatedAutomatically,
            $writingStyle,
        );

        if ($translateReTranslateSetKeys) {
            $reTranslateSetKeys = $appTranslationKeysService->findReTranslateSetKeysForLanguageId(
                $languageId,
                $limit,
                $writingStyle,
            );
            $untranslatedKeys->mergeFromOtherSet($reTranslateSetKeys);
        }
        return $untranslatedKeys;
    }

    /**
     * Returns all keys that use translation templates
     *
     * @return AppTranslationKeys
     */
    public function findTranslationKeysUsingTranslationTemplates(): AppTranslationKeys
    {
        /** @var AppTranslationKeysService $appTranslationKeysService */
        $appTranslationKeysService = DDDService::instance()->getService(AppTranslationKeysService::class);
        return $appTranslationKeysService->findKeysUsingTranslationTemplates();
    }

    /**
     * Translates keys where no translation is existent for all active locales
     *
     * @param int|null $minAppTranslationId
     * @param array|null $languageCodesToFilterFor
     * @param bool $translateReTranslateSetKeys
     * @param AppTranslationKeys|null $translationKeys
     * @param bool $previewOnly
     * @param bool $returnTexts
     * @return AppTranslationsResults
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     */
    public function generateAppTranslationsForActiveLocales(
        ?int $minAppTranslationId = null,
        ?array $languageCodesToFilterFor = null,
        bool $translateReTranslateSetKeys = false,
        ?AppTranslationKeys $translationKeys = null,
        bool $previewOnly = false,
        bool $returnTexts = false
    ): AppTranslationsResults {
        set_time_limit(3600);
        ini_set('memory_limit', '2000M');

        /** @var LanguagesService $languagesService */
        $languagesService = DDDService::instance()->getService(LanguagesService::class);

        $languages = $languagesService->findActiveLanguages(
            languageCodesToFilterFor: $languageCodesToFilterFor
        );

        $appTranslationsResults = new AppTranslationsResults();
        foreach ($languages->getElements() as $language) {
            $defaultLocale = $language->getDefaultLocale();
            if (!$defaultLocale) {
                continue;
            }
            if ($language->supportedWritingStyles == Language::WRITING_STYLE_INFORMAL || $language->supportedWritingStyles == Language::WRITING_STYLE_FORMAL_AND_INFORMAL) {
                $appTranslationsResult = $this->generateAppTranslationsForLocale(
                    $defaultLocale,
                    $minAppTranslationId,
                    writingStyle: Text::WRITING_STYLE_INFORMAL,
                    translateReTranslateSetKeys: $translateReTranslateSetKeys,
                    translationKeys: $translationKeys,
                    previewOnly: $previewOnly,
                    returnTexts: $returnTexts
                );
                $appTranslationsResults->add($appTranslationsResult);
            }
            if ($language->supportedWritingStyles == Language::WRITING_STYLE_FORMAL || $language->supportedWritingStyles == Language::WRITING_STYLE_FORMAL_AND_INFORMAL) {
                $appTranslationsResultFormal = $this->generateAppTranslationsForLocale(
                    $defaultLocale,
                    $minAppTranslationId,
                    writingStyle: Text::WRITING_STYLE_FORMAL,
                    translateReTranslateSetKeys: $translateReTranslateSetKeys,
                    translationKeys: $translationKeys,
                    previewOnly: $previewOnly,
                    returnTexts: $returnTexts
                );
                $appTranslationsResults->add($appTranslationsResultFormal);
            }
        }
        $appTranslationsResults->calculateTotalCosts();
        return $appTranslationsResults;
    }

    /**
     * Generates English translations from keys which have none,
     * by using the key text itself as the translation value
     *
     * @return AppTranslationKeys
     */
    public function generateEnglishTranslationsFromKeys(): AppTranslationKeys
    {
        /** @var LanguagesService $languagesService */
        $languagesService = DDDService::instance()->getService(LanguagesService::class);
        $englishLanguage = $languagesService->findByLanguageCode('en');

        $untranslatedKeys = $this->findUntranslatedKeysForLanguage($englishLanguage->id, 10000);
        foreach ($untranslatedKeys->getElements() as $untranslatedKey) {
            $appTranslationValue = new AppTranslationValue();
            $appTranslationValue->appTranslationKeyId = $untranslatedKey->id;
            $appTranslationValue->languageId = $englishLanguage->id;
            $appTranslationValue->writingStyle = Text::WRITING_STYLE_INFORMAL;
            $appTranslationValue->translation = $untranslatedKey->key;
            $appTranslationValue->update();
        }
        return $untranslatedKeys;
    }

    /**
     * Generates AppTranslations for provided Locale
     *
     * @param Locale $locale
     * @param int|null $minAppTranslationId
     * @param string $writingStyle
     * @param bool $translateReTranslateSetKeys
     * @param AppTranslationKeys|null $translationKeys
     * @param bool $previewOnly
     * @param bool $returnTexts
     * @return AppTranslationsResult
     * @throws BadRequestException
     * @throws InternalErrorException
     * @throws InvalidArgumentException
     * @throws ReflectionException
     * @throws NotFoundException
     */
    public function generateAppTranslationsForLocale(
        Locale $locale,
        ?int $minAppTranslationId = null,
        string $writingStyle = Text::WRITING_STYLE_INFORMAL,
        bool $translateReTranslateSetKeys = false,
        ?AppTranslationKeys $translationKeys = null,
        bool $previewOnly = false,
        bool $returnTexts = false
    ): AppTranslationsResult {
        $aiModelsService = new AIModelsService();
        $defaultTerms = $this->findDefaultTermsForLanguageId($locale->languageId);

        $aiPromptName = $writingStyle == Text::WRITING_STYLE_INFORMAL ? TranslationPrompts::APP_TRANSLATIONS_SINGLE_LOCALE_INFORMAL : TranslationPrompts::APP_TRANSLATIONS_SINGLE_LOCALE_FORMAL;
        /** @var AIPromptsService $aiPromptsService */
        $aiPromptsService = DDDService::instance()->getService(AIPromptsService::class);
        $translationsAIPrompt = $aiPromptsService->getAIPromptByName($aiPromptName);
        $translationsAIPrompt->setParameter('default_locale', $locale->languageCode . '-' . $locale->countryShortCode);
        $translationsAIPrompt->setParameter(
            'glossary',
            $this->findDefaultTermsForLanguageId(
                $locale->languageId
            )->getDefaultTermsAsAssociationPairs()
        );
        $promptTokensRequired = $translationsAIPrompt->getEstimatedInputTokens();
        $translationAIModel = $aiModelsService->getAIModelByName(Translations::AI_MODEL_FOR_TRANSLATIONS);

        $currentUserContentTokensRequired = 0;
        $currentEstimatedOutputTokensRequired = 0;
        $totalInputTokens = 0;
        $estimatedTotalOutputTokens = 0;
        $apiCalls = 0;
        $translatedCount = 0;
        $texts = $this->createAppTranslationsTextsBucket($writingStyle, $locale, $translationsAIPrompt);

        // As length of output can differ in various languages we use only 40% of the available output capacity
        $maxTokensUsableForOutput = $translationAIModel->settings->maxOutputTokens * 0.4;

        // Cursor-paginated walk over untranslated keys (id-ascending). When the caller passes
        // an explicit $translationKeys set we honour it as a single batch; otherwise we page
        // through all untranslated rows. Each completed bucket is dispatched async so that
        // the worker queue translates page N in parallel with the DB fetch of page N+1 — the
        // HTTP request returns once all dispatches are queued, not after Argus completes.
        //
        // Termination guarantees (in order):
        //   1. $batchCount === 0                            → break
        //   2. $batchCount  <  PAGE_SIZE                    → while-condition false, exit
        //   3. cursor does not strictly advance             → break (defensive: protects against
        //      non-existent edge cases like rows with id=null/0 or DB ignoring the id-filter)
        //   4. iterationCount >= APP_TRANSLATIONS_MAX_ITERATIONS → break (hard cap)
        $useCursorPagination = $translationKeys === null;
        $cursorMinId = $minAppTranslationId;
        $iterationCount = 0;

        do {
            $iterationCount++;
            if ($iterationCount > self::APP_TRANSLATIONS_MAX_ITERATIONS) {
                DDDService::instance()->getLogger()->error(
                    sprintf(
                        'AppTranslationsService::generateAppTranslationsForLocale: aborted after %d iterations '
                        . '(locale=%s, writingStyle=%s, lastCursor=%s) — possible cursor stagnation, investigate.',
                        self::APP_TRANSLATIONS_MAX_ITERATIONS,
                        $locale->languageCode . '-' . $locale->countryShortCode,
                        $writingStyle,
                        $cursorMinId === null ? 'null' : (string)$cursorMinId,
                    )
                );
                break;
            }

            if ($useCursorPagination) {
                $batch = $this->findUntranslatedKeysForLanguage(
                    $locale->languageId,
                    limit: self::APP_TRANSLATIONS_KEYS_PAGE_SIZE,
                    writingStyle: $writingStyle,
                    minAppTranslationId: $cursorMinId,
                    translateReTranslateSetKeys: $translateReTranslateSetKeys,
                );
            } else {
                $batch = $translationKeys;
            }

            $batchCount = $batch->count();
            if ($batchCount === 0) {
                break;
            }

            $maxIdInBatch = 0;
            foreach ($batch->getElements() as $translationKey) {
                $keyId = (int)($translationKey->id ?? 0);
                if ($keyId > $maxIdInBatch) {
                    $maxIdInBatch = $keyId;
                }

                $rowToTranslate = [$translationKey->id ?? '', $translationKey->getContentToTranslate()];
                $rowToTranslateWithoutHint = $rowToTranslate;
                if (isset($translationKey->translationHint)) {
                    $rowToTranslate[] = $translationKey->translationHint;
                }
                $estimatedUserContentTokens = AIPromptsService::getTokenCountForStringToTranslate(
                    json_encode($rowToTranslate),
                    'en'
                );

                $estimatedOutputTokens = AIPromptsService::getTokenCountForStringToTranslate(
                    json_encode($rowToTranslateWithoutHint),
                    $locale->languageCode
                );
                $currentUserContentTokensRequired += $estimatedUserContentTokens;
                $estimatedTotalOutputTokens += $estimatedOutputTokens;
                $currentEstimatedOutputTokensRequired += $estimatedOutputTokens;

                if ($currentEstimatedOutputTokensRequired > $maxTokensUsableForOutput) {
                    $translatedCount += $texts->count();
                    $apiCalls++;
                    $totalInputTokens += $currentUserContentTokensRequired + $promptTokensRequired;
                    $currentUserContentTokensRequired = 0;
                    $currentEstimatedOutputTokensRequired = 0;
                    if (!$previewOnly) {
                        // async = true: the AppTranslationsMessage is queued and the worker
                        // performs the Argus call. This loop keeps fetching the next page
                        // immediately rather than blocking on the AI roundtrip.
                        $texts->translate(async: true);
                    }
                    unset($texts);
                    $texts = $this->createAppTranslationsTextsBucket($writingStyle, $locale, $translationsAIPrompt);
                }
                $text = new Text(
                    content: ($translationKey?->translationTemplate ?? null) ? $translationKey?->translationTemplate : $translationKey->key,
                    language: 'en',
                    externalId: $translationKey->id,
                    requiresContext: $translationKey->requiresContext ?? false,
                    translationHint: $translationKey->translationHint ?? null
                );
                $texts->add($text);
            }

            // Forward-progress invariant: the next cursor MUST be strictly greater than the
            // current one. If it isn't, the foreach saw rows whose ids do not satisfy the
            // id >= cursorMinId filter (e.g. NULL/0 ids on transient entities, or the filter
            // wasn't applied). We break instead of risking an endless loop.
            $nextCursor = $maxIdInBatch + 1;
            if ($cursorMinId !== null && $nextCursor <= $cursorMinId) {
                DDDService::instance()->getLogger()->warning(
                    sprintf(
                        'AppTranslationsService::generateAppTranslationsForLocale: cursor would not advance '
                        . '(currentCursor=%d, maxIdInBatch=%d, batchCount=%d) — exiting pagination defensively.',
                        $cursorMinId,
                        $maxIdInBatch,
                        $batchCount,
                    )
                );
                break;
            }
            $cursorMinId = $nextCursor;

            // A short page (< page size) means we've drained the result set — exit.
        } while ($useCursorPagination && $batchCount === self::APP_TRANSLATIONS_KEYS_PAGE_SIZE);

        // handle the remaining texts
        if ($texts->count()) {
            $apiCalls++;
            $totalInputTokens += $currentUserContentTokensRequired + $promptTokensRequired;
            $translatedCount += $texts->count();
            if (!$previewOnly) {
                $texts->translate(async: true);
            }
        }
        $averageTokensPerApiCall = (int)($apiCalls ? $totalInputTokens / $apiCalls : 0);
        $textsToReturn = $returnTexts ? $texts : null;
        return new AppTranslationsResult(
            $locale,
            $translatedCount,
            $writingStyle,
            $apiCalls,
            $totalInputTokens,
            $estimatedTotalOutputTokens,
            $averageTokensPerApiCall,
            $translationAIModel->getEstimatedCostsForTokens($totalInputTokens, $estimatedTotalOutputTokens),
            $translationAIModel,
            $textsToReturn
        );
    }

    /**
     * Builds a fresh Texts bucket pre-configured for App Translations against the given locale,
     * writing style, and translation prompt. Extracted so that the paginated translate loop can
     * recreate buckets cleanly between flush points.
     *
     * @param string $writingStyle
     * @param Locale $locale
     * @param AIPrompt $translationsAIPrompt
     * @return Texts
     */
    protected function createAppTranslationsTextsBucket(
        string $writingStyle,
        Locale $locale,
        AIPrompt $translationsAIPrompt
    ): Texts {
        $texts = new Texts();
        $texts->defaultWritingStyle = $writingStyle;
        $texts->addLocaleToTranslateAtOnce(locale: $locale);
        $texts->getTranslations()->translationsAIPrompt = $translationsAIPrompt;
        return $texts;
    }

    /**
     * Translates texts, async or sync using AI
     *
     * @param Texts $texts
     * @param bool $async
     * @return void
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function translateAppTranslationsTexts(Texts &$texts, bool $async = true): void
    {
        if ($async) {
            $appTranslationsMessage = new AppTranslationsMessage($texts);
            $appTranslationsMessage->dispatch();
            return;
        }
        set_time_limit(3600);
        ini_set('memory_limit', '2000M');
        $defaultAdminAccount = DDDService::instance()->getDefaultAccountForCliOperations();
        $argusTexts = new ArgusTexts();
        $argusTexts->fromEntity($texts);
        $argusTexts->getTranslations();
        /** @var ArgusTranslations $translations */
        $translations = $argusTexts->translations;
        $translations->getArgusSettings()->toBeLoaded = true;
        ArgusLoad::$logArgusCalls = true;
        $argusTexts->argusLoad();

        $appTranslationIds = [];
        foreach ($argusTexts->translations->getElements() as $translation) {
            if (!($translation->externalId ?? null)) {
                continue;
            }
            $appTranslationValue = new AppTranslationValue();
            $appTranslationValue->context = $translation->context;
            $appTranslationValue->languageId = $translation->locale->languageId;
            // we write country only if it is not the default country for the locale
            if (!$translation->locale->isDefaultLocaleForLanguage) {
                $appTranslationValue->countryId = $translation->locale->country->id;
            }
            $appTranslationValue->writingStyle = $translation->writingStyle;
            $appTranslationValue->appTranslationKeyId = (int)$translation->externalId;
            $appTranslationValue->translation = $translation->content;
            $appTranslationValue->update();
            $appTranslationIds[] = $appTranslationValue->id;

            // even if it was set to true, we have to set to false after the translation happens
            $appTranslationValue->appTranslationKey->reTranslate = false;
            $appTranslationValue->appTranslationKey->update();
        }
        DDDService::instance()->getLogger()->info('Operations executed: ' . json_encode(ArgusApiOperations::getExecutedArgusCalls()));
        DDDService::instance()->getLogger()->info('Translations created: ' . json_encode($appTranslationIds));
    }

    /**
     * Returns AppTranslationKeys with values, based on given string array of AppTranslationKeys, and preferences.
     * It is prioritizing if set:
     * - preferredWritingStyle
     * - preferredCountryShortCode
     * But if e.g. no translations are present with the preferredWritingStyle, then it still will return any writing style
     *
     * @param array $keyStrings
     * @param int $languageId
     * @param string|null $preferredWritingStyle
     * @param string|null $preferredCountryShortCode
     * @return AppTranslationKeys|null
     */
    public function findAppTranslationKeysForStringKeysAndPreferredParameters(
        array $keyStrings,
        int $languageId,
        ?string $preferredWritingStyle = null,
        ?string $preferredCountryShortCode = null,
    ): ?AppTranslationKeys {
        if (!$preferredCountryShortCode) {
            /** @var LanguagesService $languagesService */
            $languagesService = DDDService::instance()->getService(LanguagesService::class);
            $language = $languagesService->find($languageId);
            if (!$language) {
                return null;
            }
            $defaultLocale = $language->getDefaultLocale();
            $preferredCountryShortCode = $defaultLocale?->countryShortCode;
        }

        /** @var AppTranslationValuesService $appTranslationValuesService */
        $appTranslationValuesService = DDDService::instance()->getService(AppTranslationValuesService::class);
        $appTranslationValuesForKeyStrings = $appTranslationValuesService->findByLanguageIdAndKeyStrings(
            $languageId,
            $keyStrings,
        );

        $finalKeys = new AppTranslationKeys();
        foreach ($appTranslationValuesForKeyStrings->getElements() as $appTranslationValue) {
            $translationKey = $finalKeys->getByUniqueKey(
                AppTranslationKey::uniqueKeyStatic($appTranslationValue->appTranslationKey->id)
            );
            if (!$translationKey) {
                $translationKey = $appTranslationValue->appTranslationKey;
                $finalKeys->add($translationKey);
            }
            unset($appTranslationValue->appTranslationKey);
            $translationKey->addAppTranslationValueWithPreferences(
                $appTranslationValue,
                $preferredWritingStyle,
                $preferredCountryShortCode,
            );
        }
        return $finalKeys;
    }

    /**
     * Returns AppTranslationValues for key and language ID
     *
     * @param string $keyString
     * @param int $languageId
     * @return AppTranslationValues|null
     */
    public function findAppTranslationValuesForKey(
        string $keyString,
        int $languageId,
    ): ?AppTranslationValues {
        /** @var AppTranslationValuesService $appTranslationValuesService */
        $appTranslationValuesService = DDDService::instance()->getService(AppTranslationValuesService::class);
        return $appTranslationValuesService->findByLanguageIdAndKeyStrings(
            $languageId,
            [$keyString],
        );
    }

    /**
     * Cleanup function to remove duplicates from AppTranslationValues.
     * Finds values that share the same appTranslationKeyId, languageId, countryId,
     * writingStyle, and context — keeping the oldest entry and deleting duplicates.
     *
     * @return AppTranslationValues
     */
    public function removeDuplicateAppTranslationValues(): AppTranslationValues
    {
        $removedAppTranslationValues = new AppTranslationValues();

        /** @var AppTranslationKeysService $appTranslationKeysService */
        $appTranslationKeysService = DDDService::instance()->getService(AppTranslationKeysService::class);

        /** @var AppTranslationValuesService $appTranslationValuesService */
        $appTranslationValuesService = DDDService::instance()->getService(AppTranslationValuesService::class);

        $keysRepoClass = $appTranslationKeysService->getEntitySetRepoClassInstance();
        $queryBuilder = $keysRepoClass::createQueryBuilder();
        $keysModelAlias = $keysRepoClass::getBaseModelAlias();

        $valuesRepoClass = $appTranslationValuesService->getEntityRepoClassInstance();
        $valuesModelAlias = $valuesRepoClass::getBaseModelAlias();
        $valuesOrmModel = $valuesRepoClass::BASE_ORM_MODEL;

        // Find all AppTranslationKeys that contain duplicate values for property combinations
        $queryBuilder->andWhere(
            "$keysModelAlias.id IN (
            SELECT $valuesModelAlias.appTranslationKeyId
            FROM $valuesOrmModel $valuesModelAlias
            GROUP BY $valuesModelAlias.appTranslationKeyId, $valuesModelAlias.writingStyle, $valuesModelAlias.context, $valuesModelAlias.languageId, $valuesModelAlias.countryId
            HAVING COUNT($valuesModelAlias.id) > 1
        )"
        );

        $appTranslationKeysWithDuplicates = $keysRepoClass->find($queryBuilder);

        foreach ($appTranslationKeysWithDuplicates->getElements() as $appTranslationKeyWithDuplicates) {
            $valuesSetRepoClass = $appTranslationValuesService->getEntitySetRepoClassInstance();
            $queryBuilderValues = $valuesSetRepoClass::createQueryBuilder();
            $valuesSetModelAlias = $valuesSetRepoClass::getBaseModelAlias();
            $queryBuilderValues->andWhere(
                "$valuesSetModelAlias.appTranslationKeyId = :appTranslationKeyId"
            );
            $queryBuilderValues->orderBy("$valuesSetModelAlias.created", 'asc');
            $queryBuilderValues->setParameter('appTranslationKeyId', $appTranslationKeyWithDuplicates->id);
            $potentialDuplicateValues = $valuesSetRepoClass->find($queryBuilderValues);

            // Track seen property combinations to identify duplicates
            $seenPropertyCombinations = [];
            foreach ($potentialDuplicateValues->getElements() as $potentialDuplicateValue) {
                $propertyKey = ($potentialDuplicateValue->appTranslationKeyId ?? '')
                    . '_' . ($potentialDuplicateValue->languageId ?? '')
                    . '_' . ($potentialDuplicateValue->writingStyle ?? '')
                    . '_' . ($potentialDuplicateValue->countryId ?? '0')
                    . '_' . ($potentialDuplicateValue->context ?? '');

                if (isset($seenPropertyCombinations[$propertyKey])) {
                    // This is a duplicate — delete it
                    $removedAppTranslationValues->add($potentialDuplicateValue);
                    $potentialDuplicateValue->delete();
                } else {
                    $seenPropertyCombinations[$propertyKey] = true;
                }
            }
        }
        return $removedAppTranslationValues;
    }
}
