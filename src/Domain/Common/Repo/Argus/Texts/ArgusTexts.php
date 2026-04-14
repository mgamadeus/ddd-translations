<?php

declare (strict_types=1);

namespace DDD\Domain\Common\Repo\Argus\Texts;

use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Common\Entities\Texts\Texts;

/**
 * @property ArgusText[] $elements;
 * @method ArgusText getByUniqueKey(string $uniqueKey)
 * @method ArgusText[] getElements()
 * @method ArgusText first()
 */
class ArgusTexts extends Texts
{
    use ArgusTrait;
}