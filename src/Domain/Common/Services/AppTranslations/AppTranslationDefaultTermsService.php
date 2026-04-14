<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\AppTranslations;

use DDD\Domain\Common\Entities\AppTranslations\AppTranslationDefaultTerm;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationDefaultTerms;
use DDD\Domain\Common\Entities\Languages\Language;
use DDD\Domain\Common\Entities\Languages\Languages;
use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationDefaultTerm;
use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationDefaultTerms;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Services\EntitiesService;
use DDD\Domain\Common\Services\LanguagesService;

/**
 * Service for managing AppTranslationDefaultTerm entities
 *
 * @method AppTranslationDefaultTerm find(int|string|null $entityId, bool $useEntityRegistrCache = true)
 * @method AppTranslationDefaultTerms findAll(?int $offset = null, $limit = null, bool $useEntityRegistrCache = true)
 * @method AppTranslationDefaultTerm update(DefaultObject $entity)
 * @method DBAppTranslationDefaultTerm getEntityRepoClassInstance()
 * @method DBAppTranslationDefaultTerms getEntitySetRepoClassInstance()
 */
class AppTranslationDefaultTermsService extends EntitiesService
{
    public const DEFAULT_ENTITY_CLASS = AppTranslationDefaultTerm::class;

    /**
     * Find default terms for a given Language entity
     *
     * @param Language $language The Language entity to find default terms for
     * @return AppTranslationDefaultTerms|null
     */
    public function findDefaultTermsForLanguage(Language $language): ?AppTranslationDefaultTerms
    {
        return $this->findDefaultTermsForLanguageId($language->id);
    }

    /**
     * Find default terms for a given language ID
     *
     * @param int $languageId The language ID to find default terms for
     * @return AppTranslationDefaultTerms|null
     */
    public function findDefaultTermsForLanguageId(int $languageId): ?AppTranslationDefaultTerms
    {
        $repoClass = $this->getEntitySetRepoClassInstance();
        $queryBuilder = $repoClass::createQueryBuilder();
        $baseModelAlias = $repoClass::getBaseModelAlias();

        $queryBuilder->andWhere("{$baseModelAlias}.languageId = :languageId");
        $queryBuilder->setParameter('languageId', $languageId);

        return $repoClass->find($queryBuilder);
    }

    /**
     * Find default terms for a given ISO 639-1 language code (e.g. 'de', 'en', 'fr').
     * Resolves the language code to a Language entity first, then queries default terms.
     *
     * @param string $languageCode ISO 639-1 language code
     * @return AppTranslationDefaultTerms|null Returns null if language code not found or no default terms exist
     */
    public function findDefaultTermsForLanguageCode(string $languageCode): ?AppTranslationDefaultTerms
    {
        /** @var LanguagesService $languagesService */
        $languagesService = Languages::getService();
        $language = $languagesService->findByLanguageCode($languageCode);

        if ($language === null) {
            return null;
        }

        return $this->findDefaultTermsForLanguageId($language->id);
    }
}
