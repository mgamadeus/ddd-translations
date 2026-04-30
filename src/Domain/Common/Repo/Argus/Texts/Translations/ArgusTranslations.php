<?php

declare (strict_types=1);

namespace DDD\Domain\Common\Repo\Argus\Texts\Translations;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\Common\Entities\Texts\TranslationPrompts;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Common\Entities\Texts\Texts;
use DDD\Domain\Common\Entities\Texts\Translations\Translation;
use DDD\Domain\Common\Entities\Texts\Translations\Translations;

/**
 * translates text with OpenAI API
 * @property Translation[] $elements;
 * @method Translation getByUniqueKey(string $uniqueKey)
 * @method Translation[] getElements()
 * @method Translation first()
 * @method Text|Texts getParent()
 * @property Text|Texts $parent
 */
#[ArgusLoad(loadEndpoint: 'POST:/ai/openRouter/chatCompletions', cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB, cacheTtl: ArgusCache::CACHELEVEL_NONE)]
#[ArgusLanguageModel(
    defaultAIModelName: AIModel::MODEL_OPENAI_GPT5_4_MINI,
    defaultAIPromptName: TranslationPrompts::APP_TRANSLATIONS_SINGLE_LOCALE_FORMAL,
    responseFormat: ArgusLanguageModel::RESPONSE_FORMAT_JSON_OBJECT,
)]
class ArgusTranslations extends Translations
{
    use ArgusTrait, ArgusAILanguageModelTrait;

    public function getAIPrompt(): ?AIPrompt
    {
        if (isset($this->translationsAIPrompt)) {
            return $this->translationsAIPrompt;
        }
        if (isset($this->aiPrompt)) {
            return $this->aiPrompt;
        }
        if (isset($this->getArgusLanguageModelInstance()->defaultAIPromptName)) {
            $aiPromptService = AIPrompt::getService();
            $aiPromptService->throwErrors = true;
            $this->aiPrompt = $aiPromptService->getAIPromptByName(
                $this->getArgusLanguageModelInstance()->defaultAIPromptName
            );
        }
        return $this->aiPrompt;
    }

    public function getAIPromptWithParametersApplied(): AIPrompt
    {
        $prompt = $this->getAIPrompt();
        $firstLocale = $this->localesToTranslateAtOnce->first();
        $localeString = $firstLocale->languageCode . '-' . $firstLocale->countryShortCode;
        // Set both placeholder names — `default_locale` for App-style prompts and `targetLocale`
        // for Entity-style prompts — so the same Argus repo can serve either prompt family.
        $prompt
            ->setParameter('default_locale', $localeString)
            ->setParameter('targetLocale', $localeString)
            ->setParameter('locales', $this->localesToTranslateAtOnce->getLocalesAsCommaSeparatedString());
        return $prompt;
    }

    public function getUserContent(): string|array
    {
        /** @var Texts $parent */
        $parent = $this->getParent();
        $textsToTranslate = [];
        foreach ($parent->getElements() as $text) {
            $row = [$text->externalId ?? '', $text->content];
            if (isset($text->translationHint)) {
                $row[] = $text->translationHint;
            }
            $textsToTranslate[] = $row;
        }
        // JSON_INVALID_UTF8_SUBSTITUTE replaces malformed UTF-8 byte sequences with U+FFFD
        // (the Unicode replacement character) instead of letting json_encode return false.
        // Without it, a single broken codepoint anywhere in the input would cause this method
        // to violate its `: string|array` return type and throw a TypeError. The substitute
        // path is preferable: the LLM still gets a coherent JSON payload and the worst case
        // is one ? character in the output, instead of the whole translation failing.
        $encoded = json_encode(
            $textsToTranslate,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
        if ($encoded === false) {
            // Should not be reachable with INVALID_UTF8_SUBSTITUTE in place, but the contract
            // demands string|array and json_encode could still fail on circular references etc.
            throw new \RuntimeException(
                'ArgusTranslations::getUserContent(): json_encode failed: ' . json_last_error_msg(),
            );
        }
        return $encoded;
    }

    protected function applyLoadResult(string $resultText): void
    {
        $decodedResult = null;
        if ($resultText) {
            $resultText = $this->removeProgrammingLanguageMarkup($resultText);
            $decodedResult = json_decode($resultText);
        }
        if (!$decodedResult) {
            $locale = $this->localesToTranslateAtOnce->first();
            $localeStr = $locale ? ((string)$locale->languageCode . '-' . $locale->countryShortCode) : 'unknown';
            error_log("[ArgusTranslations] applyLoadResult failed for locale $localeStr: "
                . ($resultText ? 'json_decode error: ' . json_last_error_msg() : 'empty result'));
            return;
        }
        $locale = $this->localesToTranslateAtOnce->first();
        $defaultWritingStyle = $this->getParent()->defaultWritingStyle;

        // Three response shapes are accepted:
        //   1. Entity prompt wrapper: {"targetLocale": "de-de", "translations": [[id, text], ...]}
        //   2. App prompt object:     {"<id>": "<translation>", ...}
        //   3. Raw array-of-arrays:   [[id, text], ...]
        // Detection order matters: the Entity wrapper is itself a JSON object, so we have to peek
        // at its structure before falling back to the App-style object branch.
        $entityPairs = $this->extractEntityWrapperPairs($decodedResult);
        if ($entityPairs !== null) {
            foreach ($entityPairs as $pair) {
                if (!is_array($pair) || !isset($pair[0], $pair[1])) {
                    continue;
                }
                // Must assign to a variable first — ObjectSet::add() takes its parameter
                // by reference (&...$elements), and PHP only allows variables (not direct
                // `new X(...)` expressions) to be passed to reference parameters.
                $argusTranslation = new ArgusTranslation(
                    externalId: (string)$pair[0],
                    content: (string)$pair[1],
                    locale: $locale,
                    writingStyle: $defaultWritingStyle,
                    context: Text::CONTEXT_ONE,
                );
                $this->add($argusTranslation);
            }
            return;
        }

        $isObject = is_object($decodedResult) || (is_array($decodedResult) && !array_is_list($decodedResult));
        if ($isObject) {
            $pairs = (array)$decodedResult;
            foreach ($pairs as $externalId => $content) {
                $argusTranslation = new ArgusTranslation(
                    externalId: (string)$externalId,
                    content: (string)$content,
                    locale: $locale,
                    writingStyle: $defaultWritingStyle,
                    context: Text::CONTEXT_ONE
                );
                $this->add($argusTranslation);
            }
        } else {
            foreach ($decodedResult as $translation) {
                $argusTranslation = new ArgusTranslation(
                    externalId: $translation[0],
                    content: $translation[1],
                    locale: $locale,
                    writingStyle: $defaultWritingStyle,
                    context: Text::CONTEXT_ONE
                );
                $this->add($argusTranslation);
            }
        }
    }

    /**
     * If $decoded is the Entity-prompt wrapper shape — an object with a `translations` array of
     * `[externalId, translatedText]` pairs — return that array of pairs. Otherwise return null,
     * letting the caller fall back to App-style or raw-array handling.
     *
     * @param mixed $decoded
     * @return array<int, array{0: string|int, 1: string}>|null
     */
    protected function extractEntityWrapperPairs(mixed $decoded): ?array
    {
        $translations = null;
        if (is_object($decoded) && isset($decoded->translations) && is_array($decoded->translations)) {
            $translations = $decoded->translations;
        } elseif (is_array($decoded) && !array_is_list($decoded) && isset($decoded['translations']) && is_array($decoded['translations'])) {
            $translations = $decoded['translations'];
        }
        if ($translations === null || $translations === []) {
            return null;
        }
        // Confirm the shape: list of [id, text] pairs.
        $first = $translations[0] ?? null;
        if (!is_array($first) || !array_is_list($first) || count($first) < 2) {
            return null;
        }
        return $translations;
    }
}