<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\AppTranslations;

use DDD\Domain\Common\Entities\AppTranslations\AppTranslationKey;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * Database repository for AppTranslationKey entities
 *
 * @method AppTranslationKey find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method AppTranslationKey update(DefaultObject &$entity, int $depth = 1)
 * @property DBAppTranslationKeyModel $ormInstance
 */
class DBAppTranslationKey extends DBEntity
{
    public const BASE_ENTITY_CLASS = AppTranslationKey::class;
    public const BASE_ORM_MODEL = DBAppTranslationKeyModel::class;
}
