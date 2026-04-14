<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations;

use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\Domain\Base\Entities\ObjectSet;

/**
 * @property AppTranslationsResult[] $elements;
 * @method AppTranslationsResult getByUniqueKey(string $uniqueKey)
 * @method AppTranslationsResult[] getElements()
 * @method AppTranslationsResult first()
 */
class AppTranslationsResults extends ObjectSet
{
    /** @var MoneyAmount|null  */
    public ?MoneyAmount $totalCosts;

    /**
     * @return void Calculates total costs for all Translations
     */
    public function calculateTotalCosts(): void
    {
        $this->totalCosts = new MoneyAmount(0, 'USD');
        foreach ($this->elements as $element) {
            if (!$element?->estimatedCosts ?? null) {
                continue;
            }
            $this->totalCosts->amount += $element->estimatedCosts->amount;
        }
    }
}