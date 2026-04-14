<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations;

use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationKeys;
use DDD\Domain\Common\Services\AppTranslations\AppTranslationKeysService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * @property AppTranslationKey[] $elements;
 * @method AppTranslationKey|null first()
 * @method AppTranslationKey|null getByUniqueKey(string $uniqueKey)
 * @method AppTranslationKey[] getElements()
 * @method static AppTranslationKeysService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAppTranslationKeys::class)]
class AppTranslationKeys extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = AppTranslationKeysService::class;
}
