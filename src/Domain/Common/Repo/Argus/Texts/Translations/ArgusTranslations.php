<?php

declare (strict_types=1);

namespace DDD\Domain\Common\Repo\Argus\Texts\Translations;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Repo\Argus\Attributes\ArgusLanguageModel;
use DDD\Domain\Common\TranslationPrompts;
use DDD\Domain\AI\Repo\Argus\Traits\ArgusAILanguageModelTrait;
use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Common\Entities\Texts\Texts;
use DDD\Domain\Common\Entities\Texts\Translations\Translation;
use DDD\Domain\Common\Entities\Texts\Translations\Translations;
use DDD\Infrastructure\Services\AppService;

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
    defaultAIModelName: AIModel::MODEL_OPENAI_GPT5_2,
    defaultAIPromptName: TranslationPrompts::APP_TRANSLATIONS_SINGLE_LOCALE_FORMAL,
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
        $langaugeToTranslate = $firstLocale->languageCode;
        $defaultLocale = $firstLocale;
        $prompt->setParameter('default_locale', $defaultLocale->languageCode . '-' . $defaultLocale->countryShortCode)
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
        return json_encode($textsToTranslate, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    protected function applyLoadResult(string $resultText): void
    {
        $decodedResult = null;
        if ($resultText) {
            $resultText = $this->removeProgrammingLanguageMarkup($resultText);
            $decodedResult = json_decode($resultText);
        }
        if (!$decodedResult) {
            return;
        }
        $translations = $decodedResult;
        $locale = $this->localesToTranslateAtOnce->first();
        $defaultWritingStyle = $this->getParent()->defaultWritingStyle;
        foreach ($translations as $translation) {
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