<?php

declare (strict_types=1);

namespace DDD\Domain\Common\Entities\Texts\Buckets;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Domain\Common\Entities\Texts\Texts;
use DDD\Domain\Common\Repo\Argus\Texts\Buckets\ArgusTextsBuckets;

/**
 * @property Texts[] $elements;
 * @method Texts getByUniqueKey(string $uniqueKey)
 * @method Texts[] getElements()
 * @method Texts first()
 */
#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusTextsBuckets::class)]
class TextsBuckets extends ObjectSet
{
}