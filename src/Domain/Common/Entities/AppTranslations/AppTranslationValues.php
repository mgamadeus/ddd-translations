<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations;

use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationValues;
use DDD\Domain\Common\Services\AppTranslations\AppTranslationValuesService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * @property AppTranslationValue[] $elements;
 * @method AppTranslationValue|null first()
 * @method AppTranslationValue|null getByUniqueKey(string $uniqueKey)
 * @method AppTranslationValue[] getElements()
 * @method static AppTranslationValuesService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAppTranslationValues::class)]
class AppTranslationValues extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = AppTranslationValuesService::class;

    /**
     * @return AppTranslationKeys Returns all AppTranslationKeys associated with current AppTranslationValues
     */
    public function getAppTranslationKeys(): AppTranslationKeys
    {
        $appTranslationKeys = new AppTranslationKeys();
        foreach ($this->getElements() as $appTranslationValue) {
            $appTranslationKeys->add($appTranslationValue->appTranslationKey);
        }
        return $appTranslationKeys;
    }
}
