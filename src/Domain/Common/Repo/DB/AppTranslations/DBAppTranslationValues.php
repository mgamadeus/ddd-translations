<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\AppTranslations;

use DDD\Domain\Common\Entities\AppTranslations\AppTranslationValues;
use DDD\Domain\Base\Repo\DB\DBEntitySet;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * @method AppTranslationValues find(DoctrineQueryBuilder $queryBuilder = null, $useEntityRegistrCache = true)
 */
class DBAppTranslationValues extends DBEntitySet
{
    public const string BASE_REPO_CLASS = DBAppTranslationValue::class;
    public const string BASE_ENTITY_SET_CLASS = AppTranslationValues::class;
}
