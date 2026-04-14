<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\AppTranslations;

use DDD\Domain\Common\Entities\AppTranslations\AppTranslationKey;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationKeys;
use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationKey;
use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationKeys;
use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationValue;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Services\EntitiesService;

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
            $subQuery .= " AND atvSub.writingStyle = :writingStyle";
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
}
