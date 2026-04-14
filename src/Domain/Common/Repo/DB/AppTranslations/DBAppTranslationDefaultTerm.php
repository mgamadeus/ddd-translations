<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\AppTranslations;

use DDD\Domain\Common\Entities\AppTranslations\AppTranslationDefaultTerm;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * Database repository for AppTranslationDefaultTerm entities
 *
 * @method AppTranslationDefaultTerm find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method AppTranslationDefaultTerm update(DefaultObject &$entity, int $depth = 1)
 * @property DBAppTranslationDefaultTermModel $ormInstance
 */
class DBAppTranslationDefaultTerm extends DBEntity
{
    public const BASE_ENTITY_CLASS = AppTranslationDefaultTerm::class;
    public const BASE_ORM_MODEL = DBAppTranslationDefaultTermModel::class;
}
