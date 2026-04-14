<?php

declare (strict_types=1);

namespace DDD\Domain\Common\Entities\Texts;

use DDD\Domain\Common\Entities\Locales\Locale;
use DDD\Domain\Common\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\Common\Entities\Texts\DetectedLanguage;
use DDD\Domain\Common\Entities\Texts\Translations\Translation;
use DDD\Domain\Common\Entities\Texts\Translations\Translations;
use DDD\Domain\Common\Repo\Argus\Texts\ArgusText;
use DDD\Domain\Common\Repo\Argus\Texts\Embeddings\ArgusTextEmbedding;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;
use DDD\Infrastructure\Libs\Datafilter;
use DDD\Infrastructure\Traits\Serializer\Attributes\HideProperty;
use DDD\Infrastructure\Validation\Constraints\Choice;

/**
 * @property Texts $parent
 * @method Texts getParent()
 */
#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusText::class)]
class Text extends ValueObject
{
    /** @var string Formal writing style */
    public const string WRITING_STYLE_FORMAL = 'FORMAL';

    /** @var string Informal writing style */
    public const string WRITING_STYLE_INFORMAL = 'INFORMAL';

    /** @var string The context of the translation is singular */
    public const string CONTEXT_ONE = 'ONE';

    /** @var string The context of the translation is plural */
    public const string CONTEXT_MANY = 'MANY';

    /** @var string|null The content of the text */
    public ?string $content;

    /** @var string|null Optional hint to provide more context for translations */
    public ?string $translationHint;

    /** @var string|null The locale of the text */
    #[HideProperty]
    public ?Locale $locale;

    /** @var string Writing style of the content (personal or formal) */
    #[Choice([self::WRITING_STYLE_INFORMAL, self::WRITING_STYLE_FORMAL])]
    public string $writingStyle = self::WRITING_STYLE_INFORMAL;

    /** @var Texts Translations of the text */
    public Translations $translations;

    /** @var int|string|null External id to reference the text, e.g. id of AppTranslationKey */
    public int|string|null $externalId;

    /** @var bool If true, the main subject of the translation key requires a context, means one and many */
    public bool $requiresContext = false;

    /** @var string The translation context, one or many for singular and plural differentiation (e.g. for Project, Projects depending on number) */
    #[Choice([self::CONTEXT_ONE, self::CONTEXT_MANY])]
    public string $context = self::CONTEXT_ONE;

    /** @var TextEmbedding Generated with Embedding Model */
    #[LazyLoad(repoType: LazyLoadRepo::ARGUS)]
    public TextEmbedding $embedding;

    /** @var DetectedLanguage|null AI detected language for this text */
    #[DatabaseColumn(ignoreProperty: true)]
    #[LazyLoad(repoType: LazyLoadRepo::ARGUS)]
    public ?DetectedLanguage $detectedLanguage = null;

    public function __construct(
        ?string $content = null,
        ?string $language = null,
        ?string $countryShortCode = null,
        ?Locale $locale = null,
        string $writingStyle = self::WRITING_STYLE_FORMAL,
        int|string|null $externalId = null,
        bool $requiresContext = false,
        ?string $translationHint = null,
        string $context = self::CONTEXT_ONE
    ) {
        parent::__construct();
        $this->content = $content;
        $this->locale = Locale::getLocaleForInput($language, $countryShortCode, $locale);
        $this->writingStyle = $writingStyle;
        $this->externalId = $externalId;
        $this->requiresContext = $requiresContext;
    }

    public function getTranslationForLocale(
        ?Locale $locale = null
    ): ?Translation {
        return $this?->translations?->getTranslationForLocale($locale) ?? null;
    }

    /**
     * Add Locale to be translated
     * @param string|null $language
     * @param string|null $countryShortCode
     * @param Locale|null $locale
     * @return void
     */
    public function addLocaleToTranslate(
        ?Locale $locale = null,
        string $writingStyle = self::WRITING_STYLE_FORMAL
    ): void {

        if (!isset($this->translations)) {
            $this->translations = new Translations();
            $this->addChildren($this->translations);
        }
        $translation = new Translation(locale: $locale, writingStyle: $writingStyle);
        $this->translations->add($translation);
    }

    public function getWordCount()
    {
        return Datafilter::wordcount($this->content);
    }

    public function uniqueKey(): string
    {
        return static::getUniqueKeyForParamters(
            $this->externalId ?? null,
            $this->content ?? null,
            $this->locale ?? null,
            $this->writingStyle ?? self::WRITING_STYLE_INFORMAL,
            $this->context ?? self::CONTEXT_ONE

        );
    }

    public static function getUniqueKeyForParamters(
        string|int|null $externalId = null,
        ?string $content = null,
        ?Locale $locale = null,
        ?string $writingStyle = self::WRITING_STYLE_INFORMAL,
        ?string $context = self::CONTEXT_ONE
    ): string {
        $key = (string)$externalId ?? '';
        if (!$key) {
            $key = $content ?? '';
        }
        $key .= '_' . ($locale?->uniqueKey() ?? '') . '_' . ($writingStyle ?? '') . '_' . $context;
        //$key = md5($key);
        return Text::uniqueKeyStatic($key);
    }
}