<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\Texts;

use DDD\Domain\Common\Repo\Argus\Texts\ArgusDetectedLanguage;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * AI-detected language for a Text.
 *
 * @property Text $parent
 * @method Text getParent()
 */
#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusDetectedLanguage::class)]
class DetectedLanguage extends ValueObject
{
    /** @var string ISO 639-1 language code (lowercase), e.g. "de", "en" */
    public ?string $languageCode;

    public function uniqueKey(): string
    {
        // Avoid recursion if parent is an ObjectSet
        if ($parent = $this->getParent() && $this->getParent() instanceof Text) {
            $key = $this->getParent()->uniqueKey();
        } else {
            $key = spl_object_id($this);
        }
        return self::uniqueKeyStatic($key);
    }
}
