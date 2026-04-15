<?php

declare (strict_types=1);

namespace DDD\Domain\Common\Entities\Texts\Translations;

use DDD\Domain\AI\Entities\Models\AIModel;
use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\Common\Entities\Locales\Locale;
use DDD\Domain\Common\Entities\Locales\Locales;
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Common\Entities\Texts\Texts;
use DDD\Domain\Common\Repo\Argus\Texts\Translations\ArgusTranslations;
use DDD\Domain\Base\Entities\BaseObject;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;

/**
 * @property Translation[] $elements;
 * @method Translation getByUniqueKey(string $uniqueKey)
 * @method Translation[] getElements()
 * @method Translation first()
 * @method Text|Texts getParent()
 * @property Text|Texts $parent
 */
#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusTranslations::class)]
class Translations extends Texts
{
    /** @var string Default model used for Translations */
    public const string AI_MODEL_FOR_TRANSLATIONS = AIModel::MODEL_OPENAI_GPT5_4_MINI;

    /** @var Locales The locales to be translated at once in a batch */
    public Locales $localesToTranslateAtOnce;

    /** @var AIPrompt The AI Promt to use for translations */
    public AIPrompt $translationsAIPrompt;

    protected array $translationsByLocale = [];

    protected array $translationsByLocaleAndWritingStyleAndContext = [];

    public function add(?BaseObject &...$elements): void
    {
        foreach ($elements as $translation) {
            /** @var Translation $translation */
            $this->translationsByLocale[$translation->locale . ''] = $translation;
            $this->translationsByLocaleAndWritingStyleAndContext[$translation->locale . '_' .$translation->writingStyle . '_' .$translation->context] = $translation;
        }
        parent::add(...$elements);
    }

    public function getTranslationForLocale(Locale $locale): ?Translation
    {
        return $this->translationsByLocale[$locale . ''] ?? null;
    }

    public function getTranslationForParameters(
        ?string $language = null,
        ?string $countryShortCode = null,
        ?Locale $locale = null,
        string|int|null $externalId = null,
        ?string $writingStyle = Text::WRITING_STYLE_INFORMAL,
        ?string $context = Text::CONTEXT_ONE
    ): ?Translation {
        $locale = Locale::getLocaleForInput($language, $countryShortCode, $locale);
        $key = $locale . '_' . $writingStyle . '_' .$context;
        return $this->translationsByLocaleAndWritingStyleAndContext[$key] ?? null;
    }

    /**
     * Adds Locale to Locales that have to be translated at once, e.g. for multiple texts
     * @param Locale|null $locale
     * @return void
     */
    public function addLocaleToTranslateAtOnce(
        ?Locale $locale = null
    ): void {
        if (!isset($this->localesToTranslateAtOnce)) {
            $this->localesToTranslateAtOnce = new Locales();
            $this->addChildren($this->localesToTranslateAtOnce);
        }
        $this->localesToTranslateAtOnce->add($locale);
    }
}