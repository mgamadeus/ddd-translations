<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\AppTranslations;

use DDD\Domain\Common\Entities\AppTranslations\AppTranslationValue;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationValues;
use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationValue;
use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationValues;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Services\EntitiesService;

/**
 * Service for managing AppTranslationValue entities
 *
 * @method AppTranslationValue find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AppTranslationValues findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method DBAppTranslationValue getEntityRepoClassInstance()
 * @method AppTranslationValue update(DefaultObject $entity)
 * @method DBAppTranslationValues getEntitySetRepoClassInstance()
 */
class AppTranslationValuesService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = AppTranslationValue::class;

    /**
     * Find AppTranslationValues for a given language ID and key strings
     *
     * @param int $languageId The language ID to filter by
     * @param array $keyStrings Array of key strings to find values for
     * @return AppTranslationValues
     */
    public function findByLanguageIdAndKeyStrings(
        int $languageId,
        array $keyStrings,
    ): AppTranslationValues {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();

        $queryBuilder->andWhere("{$baseModelAlias}.languageId = :languageId");
        $queryBuilder->setParameter('languageId', $languageId);

        // Join with keys table to filter by key strings
        $keyOrmModel = \DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationKey::BASE_ORM_MODEL;
        $queryBuilder->innerJoin(
            $keyOrmModel,
            'atk',
            'WITH',
            "atk.id = {$baseModelAlias}.appTranslationKeyId AND atk.key IN (:keyStrings)"
        );
        $queryBuilder->setParameter('keyStrings', $keyStrings);

        return $repoClass->find($queryBuilder);
    }

    /**
     * Find AppTranslationValues by language ID and key ID
     *
     * @param int $languageId The language ID
     * @param int $appTranslationKeyId The translation key ID
     * @return AppTranslationValues
     */
    public function findByLanguageIdAndKeyId(
        int $languageId,
        int $appTranslationKeyId,
    ): AppTranslationValues {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();

        $queryBuilder->andWhere("{$baseModelAlias}.languageId = :languageId");
        $queryBuilder->andWhere("{$baseModelAlias}.appTranslationKeyId = :appTranslationKeyId");
        $queryBuilder->setParameter('languageId', $languageId);
        $queryBuilder->setParameter('appTranslationKeyId', $appTranslationKeyId);

        return $repoClass->find($queryBuilder);
    }

    /**
     * Find AppTranslationValues containing given terms for a language
     *
     * @param int $languageId The language ID
     * @param array $terms Array of terms to search for in translations
     * @return AppTranslationValues
     */
    public function findValuesContainingTerms(
        int $languageId,
        array $terms,
    ): AppTranslationValues {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();

        $queryBuilder->andWhere("{$baseModelAlias}.languageId = :languageId");
        $queryBuilder->setParameter('languageId', $languageId);

        $orConditions = [];
        foreach ($terms as $index => $term) {
            $paramName = "term_{$index}";
            $orConditions[] = "{$baseModelAlias}.translation LIKE :{$paramName}";
            $queryBuilder->setParameter($paramName, "%{$term}%");
        }
        if (!empty($orConditions)) {
            $queryBuilder->andWhere('(' . implode(' OR ', $orConditions) . ')');
        }

        return $repoClass->find($queryBuilder);
    }
}
