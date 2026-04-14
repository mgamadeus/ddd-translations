<?php

declare (strict_types=1);

namespace DDD\Domain\Common\Entities\Texts;

use DDD\Domain\Common\Entities\Locales\Locale;
use DDD\Domain\Common\Entities\Locales\Locales;
use DDD\Domain\Common\Entities\Texts\Translations\Translations;
use DDD\Domain\Common\Repo\Argus\Texts\ArgusTexts;
use DDD\Domain\Common\Services\AppTranslations\AppTranslationsService;
use DDD\Domain\Base\Entities\BaseObject;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\ObjectSet;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use DDD\Infrastructure\Services\DDDService;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Override;

/**
 * @property Text[] $elements;
 * @method Text getByUniqueKey(string $uniqueKey)
 * @method Text[] getElements()
 * @method Text first()
 */
#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusTexts::class)]
class Texts extends ObjectSet
{
    /** @var string Writing style of the content (personal or formal) */
    #[Choice([Text::WRITING_STYLE_INFORMAL, Text::WRITING_STYLE_FORMAL])]
    public string $defaultWritingStyle = Text::WRITING_STYLE_INFORMAL;

    /** @var Translations|null Tranlsations can relate to a single text or to multiple texts at once */
    public ?Translations $translations;

    /** @var Text[] $textsByExternalId */
    protected array $textsByExternalId = [];

    /** @var string|null Optional identifier to be used for uniqueKey when added e.g. to TextBuckets */
    public ?string $identifier = null;

    #[Override]
    public function add(?BaseObject &...$elements): void
    {
        parent::add(...$elements);
        foreach ($elements as $element) {
            if ($element instanceof Text) {
                $this->textsByExternalId[$element->externalId] = $element;
            }
        }
    }

    public function getByExternalId(string $externalId): ?Text
    {
        return $this->textsByExternalId[$externalId] ?? null;
    }

    /**
     * Adds Locales to Locales that have to be translated at once for multiple texts
     * @param Locales $locales
     * @return void
     */
    public function addLocalesToTranslateAtOnce(Locales $locales): void
    {
        foreach ($locales as $locale) {
            $this->addLocaleToTranslateAtOnce(locale: $locale);
        }
    }

    /**
     * Adds Locale to Locales that have to be translated at once for multiple texts
     * @param Locale|null $locale
     * @return void
     */
    public function addLocaleToTranslateAtOnce(
        ?Locale $locale = null
    ): void
    {
        $this->getTranslations()->addLocaleToTranslateAtOnce($locale);
    }

    public function getTranslations(): Translations
    {
        if (!isset($this->translations)) {
            $this->translations = new Translations();
        }
        $this->addChildren($this->translations);
        return $this->translations;
    }

    /**
     * Translates texts, async or sync using AI
     * @param bool $async
     * @return void
     * @throws InternalErrorException
     * @throws \ReflectionException
     */
    public function translate(bool $async = true): void
    {
        /** @var AppTranslationsService $appTranslationsService */
        $appTranslationsService = DDDService::instance()->getService(AppTranslationsService::class);
        $appTranslationsService->translateAppTranslationsTexts($this, $async);
    }

    public function uniqueKey(): string
    {
        if (isset($this->identifier)) {
            return self::uniqueKeyStatic($this->identifier);
        }
        return parent::uniqueKey();
    }


}