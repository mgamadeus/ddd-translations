<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations\Completeness;

use DDD\Domain\Common\Entities\Locales\Locale;
use DDD\Domain\Base\Entities\ValueObject;

class AppTranslationCompleteness extends ValueObject
{
    /** @var Locale The locale this completeness refers to */
    public Locale $locale;

    /** @var int Total number of translation keys */
    public int $totalKeys = 0;

    /** @var int Number of translated values for informal (personal) writing style */
    public int $informalTranslatedCount = 0;

    /** @var int Number of translated values for formal (impersonal) writing style */
    public int $formalTranslatedCount = 0;

    public function __construct(
        Locale $locale,
        int $totalKeys = 0,
        int $informalTranslatedCount = 0,
        int $formalTranslatedCount = 0,
    ) {
        $this->locale = $locale;
        $this->totalKeys = $totalKeys;
        $this->informalTranslatedCount = $informalTranslatedCount;
        $this->formalTranslatedCount = $formalTranslatedCount;
        parent::__construct();
    }

    /** @return float Completeness percentage (0-100) for informal translations */
    public function getInformalCompletenessPercent(): float
    {
        if ($this->totalKeys === 0) {
            return 0.0;
        }
        return round(($this->informalTranslatedCount / $this->totalKeys) * 100, 2);
    }

    /** @return float Completeness percentage (0-100) for formal translations */
    public function getFormalCompletenessPercent(): float
    {
        if ($this->totalKeys === 0) {
            return 0.0;
        }
        return round(($this->formalTranslatedCount / $this->totalKeys) * 100, 2);
    }

    /** @return int Number of missing informal translations */
    public function getMissingInformalCount(): int
    {
        return max(0, $this->totalKeys - $this->informalTranslatedCount);
    }

    /** @return int Number of missing formal translations */
    public function getMissingFormalCount(): int
    {
        return max(0, $this->totalKeys - $this->formalTranslatedCount);
    }

    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->locale->uniqueKey());
    }
}
