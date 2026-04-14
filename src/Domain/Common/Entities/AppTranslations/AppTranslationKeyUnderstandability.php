<?php

declare (strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations;

use DDD\Domain\Common\Repo\Argus\AppTranslations\ArgusAppTranslationKeyUnderstandability;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\ValueObject;

/**
 * Stores information related to the understandability of an AppTranslationKey with the purpose
 * of a correct and accurate translation given the context of the Application
 * @var AppTranslationKey $parent
 * @method AppTranslationKey getParent()
 */
#[LazyLoadRepo(LazyLoadRepo::ARGUS, repoClass: ArgusAppTranslationKeyUnderstandability::class)]
class AppTranslationKeyUnderstandability extends ValueObject
{
    /**
     * @var float
     *
     * Acceptance threshold for understandability scores
     * Scores higher than this threshold are considered acceptable for translation
     */
    public const float SCORE_ACCEPTANCE_THRESHOLD = 0.8;

    /**
     * @var float Understandability score for a TranslationKey
     *
     * An evaluation score representing the clarity and understandability of a Translation Key.
     * The score is a float value between 0 and 1, where 1 means perfectly understandable and
     * 0 means that the key is not precise and may possibly be translated incorrectly.
     */
    public float $understandabilityScore;

    /** @var bool If true, TrnaslationKey is acceptable, else not */
    public bool $isAcceptableTranslationKey = false;

    /** @var string Reasoning for evaluation score */
    public string $reasoningForEvaluationScore;

    public function setIsAcceptableTranslationKey(): void
    {
        $this->isAcceptableTranslationKey = $this->understandabilityScore > self::SCORE_ACCEPTANCE_THRESHOLD;
    }

    public function uniqueKey(): string
    {
        $key = '';
        if (isset($this->getParent()->id)) {
            $key = $this->getParent()->id;
        } else {
            $key = (isset($this->getParent()->key) ? $this->getParent()->key : '') . '_' . (isset(
                    $this->getParent()->translationHint
                ) ? $this->getParent()->translationHint : '');
        }
        return self::uniqueKeyStatic($key);
    }
}