<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations\Completeness;

use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property AppTranslationCompleteness[] $elements;
 * @method AppTranslationCompleteness|null getByUniqueKey(string $uniqueKey)
 * @method AppTranslationCompleteness[] getElements()
 * @method AppTranslationCompleteness|null first()
 */
class AppTranslationCompletenesses extends ObjectSet
{
}
