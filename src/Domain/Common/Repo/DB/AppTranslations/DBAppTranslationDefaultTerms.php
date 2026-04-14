<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\AppTranslations;

use DDD\Domain\Common\Entities\AppTranslations\AppTranslationDefaultTerms;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AppTranslationDefaultTerms find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAppTranslationDefaultTerms extends DBEntitySet
{
    public const BASE_REPO_CLASS = DBAppTranslationDefaultTerm::class;
    public const BASE_ENTITY_SET_CLASS = AppTranslationDefaultTerms::class;
}
