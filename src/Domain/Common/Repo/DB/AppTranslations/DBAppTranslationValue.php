<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\AppTranslations;

use DDD\Domain\Common\Entities\AppTranslations\AppTranslationValue;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Domain\Base\Repo\DB\DBEntity;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineQueryBuilder;

/**
 * Database repository for AppTranslationValue entities
 *
 * @method AppTranslationValue find(DoctrineQueryBuilder|string|int $idOrQueryBuilder, bool $useEntityRegistryCache = true, ?DoctrineModel &$loadedOrmInstance = null, bool $deferredCaching = false)
 * @method AppTranslationValue update(DefaultObject &$entity, int $depth = 1)
 * @property DBAppTranslationValueModel $ormInstance
 */
class DBAppTranslationValue extends DBEntity
{
    public const string BASE_ENTITY_CLASS = AppTranslationValue::class;
    public const string BASE_ORM_MODEL = DBAppTranslationValueModel::class;
}
