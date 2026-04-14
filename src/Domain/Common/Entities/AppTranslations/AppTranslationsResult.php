<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\Common\Entities\Locales\Locale;
use DDD\Domain\Common\Entities\Money\MoneyAmount;
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Common\Entities\Texts\Texts;
use DDD\Domain\Base\Entities\DefaultObject;
use DDD\Infrastructure\Validation\Constraints\Choice;

class AppTranslationsResult extends DefaultObject
{
    /** @var Locale Locale that has been translated */
    public ?Locale $locale;

    /** @var string The writing style of the translations */
    #[Choice([Text::WRITING_STYLE_FORMAL, Text::WRITING_STYLE_INFORMAL])]
    public string $writingStyle = Text::WRITING_STYLE_INFORMAL;

    /** @var int|null The number of translated keys */
    public ?int $translatedKeysCount;

    /** @var int|null The number of API calls required */
    public ?int $apiCalls;

    /** @var int|null The number of input tokens required */
    public ?int $requiredInputTokens;

    /** @var int|null The number of estimated output tokens required */
    public ?int $requiredOuputTokens;

    /** @var int|null The average number of input tokens per API call */
    public ?int $averageInputTokensPerApiCall;

    /** @var MoneyAmount|null Estimated costs for required API operations */
    public ?MoneyAmount $estimatedCosts;

    /** @var AIModel|null AIModel used for translations */
    public ?AIModel $translationAIModel;

    /** @var Texts|null Optional Texts translated */
    public ?Texts $texts;

    public function __construct(
        ?Locale &$locale = null,
        int $translatedKeysCount = 0,
        string $writingStyle = Text::WRITING_STYLE_INFORMAL,
        int $apiCalls = 0,
        int $requiredInputTokens = 0,
        int $requiredOuputTokens = 0,
        int $averageInputTokensPerApiCall = 0,
        ?MoneyAmount $estimatedCosts = null,
        ?AIModel $translationAIModel = null,
        ?Texts $texts = null
    ) {
        $this->locale = $locale;
        $this->translatedKeysCount = $translatedKeysCount;
        $this->writingStyle = $writingStyle;
        $this->apiCalls = $apiCalls;
        $this->requiredInputTokens = $requiredInputTokens;
        $this->requiredOuputTokens = $requiredOuputTokens;
        $this->averageInputTokensPerApiCall = $averageInputTokensPerApiCall;
        $this->estimatedCosts = $estimatedCosts;
        $this->translationAIModel = $translationAIModel;
        $this->texts = $texts;
        parent::__construct();
    }


    public function uniqueKey(): string
    {
        return self::uniqueKeyStatic($this->locale->uniqueKey() . ' ' . $this->writingStyle);
    }
}