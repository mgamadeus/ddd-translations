<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\Argus\Texts;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\Common\Entities\Texts\TranslationPrompts;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Common\Entities\Texts\DetectedLanguage;
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;

/**
 * Detects the primary language for a Text using an inexpensive AI model.
 *
 * @method Text getParent()
 * @property Text $parent
 * @method DetectedLanguage toEntity()
 */
#[ArgusLoad(loadEndpoint: 'POST:/ai/openRouter/chatCompletions', cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB, cacheTtl: ArgusCache::CACHELEVEL_NONE)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_OPENAI_GPT5_4_MINI,
    defaultAIPromptName: TranslationPrompts::DETECTED_LANGUAGE,
    temperature: 0,
    responseFormat: ArgusLanguageModel::RESPONSE_FORMAT_JSON_OBJECT
)]
class ArgusDetectedLanguage extends DetectedLanguage
{
    use ArgusTrait, ArgusAILanguageModelTrait;

    public function lazyload(Text &$text, LazyLoad &$lazyloadAttributeInstance): ?DetectedLanguage
    {
        $this->setParent($text);
        $this->argusLoad(
            useArgusEntityCache: $lazyloadAttributeInstance->useCache,
            useApiACallCache: $lazyloadAttributeInstance->useCache,
        );
        return $this->toEntity();
    }

    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        return $this->getAIPrompt();
    }

    public function getUserContent(): string|array
    {
        $content = ($this->getParent()->content ?? '');
        $content = trim($content);
        if ($content === '') {
            return '';
        }

        // Token-saver: use at most the first 100 words.
        $words = preg_split('/\s+/u', $content, -1, PREG_SPLIT_NO_EMPTY);
        if (!$words) {
            return '';
        }

        $words = array_slice($words, 0, 100);
        return implode(' ', $words);
    }

    protected function applyLoadResult(string $resultText): void
    {
        $clean = $this->removeProgrammingLanguageMarkup($resultText);
        $decoded = json_decode($clean, true);
        $languageCode = null;

        if (is_array($decoded)) {
            $languageCode = $decoded['languageCode'] ?? null;
        }

        $languageCode = is_string($languageCode) ? strtolower(trim($languageCode)) : '';

        // Ensure a compact ISO-639-1-style code. If parsing failed, fall back to "en".
        if (!preg_match('/^[a-z]{2}$/', $languageCode)) {
            $languageCode = 'en';
        }

        $this->languageCode = $languageCode;
    }
}
