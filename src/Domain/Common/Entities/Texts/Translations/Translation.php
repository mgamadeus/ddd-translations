<?php

declare (strict_types=1);

namespace DDD\Domain\Common\Entities\Texts\Translations;

use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Common\Entities\Texts\Texts;

/**
 * @property Translations $parent
 * @method Translations getParent()
 */
class Translation extends Text
{
    public function getOriginalText(): ?Text
    {
        if ($this->getParent()->getParent() instanceof Texts) {
            return null;
        }
        return $this->getParent()->getParent();
    }
}