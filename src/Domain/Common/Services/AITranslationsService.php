<?php

namespace DDD\Domain\Common\Services;

use DDD\Domain\AI\Entities\Prompts\AIPrompt;
use DDD\Domain\AI\Services\AIPromptsService;
use DDD\Domain\Common\Entities\Texts\TranslationPrompts;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Common\Entities\Locales\Locale;
use DDD\Domain\Common\Entities\Locales\Locales;
use DDD\Domain\Common\Entities\Texts\Buckets\TextsBuckets;
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Common\Entities\Texts\Texts;
use DDD\Domain\Common\Repo\Argus\Texts\Buckets\ArgusTextsBuckets;
use DDD\Domain\Common\Repo\Argus\Texts\Translations\ArgusTranslations;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\Translatable\TranslatableTrait;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Services\DDDService;
use GuzzleHttp\Exception\GuzzleException;
use ReflectionException;

class AITranslationsService
{
    /** @var int Maximum cumulative character count per translation bucket before splitting into a new batch */
    protected const int MAX_CHARS_PER_BUCKET = 1000;

    /**
     * Translates all Entities of given EntitySet
     * @param EntitySet $entitySet
     * @param Locales $localesToTranslate
     * @return EntitySet
     * @throws GuzzleException
     * @throws ReflectionException
     */
    public function translateEntities(
        EntitySet $entitySet,
        ?Locales $localesToTranslate = null,
        bool $storeAutomatically = false,
        bool $async = true
    ): EntitySet
    {
        $texts = new Texts();
        if (!$localesToTranslate) {
            $localesToTranslate = Translatable::getActiveLocalesSet();
        }
        $entitiesById = [];
        foreach ($entitySet->getElements() as $entity) {
            $entityReflectionClass = ReflectionClass::instance($entity::class);
            if (!$entityReflectionClass->hasTrait(TranslatableTrait::class)) {
                continue;
            }
            /** @var TranslatableTrait $entity */
            $entitiesById[spl_object_id($entity)] = $entity;
            $translatableProperties = $entity->getTranslatableProperties();
            foreach ($translatableProperties as $translatableProperty) {
                $propertyName = $translatableProperty->getName();

                $textForProperty = $this->getTextToTranslateFromTranslationsArray(
                    $localesToTranslate,
                    $entity->getTranslationInfos()->getTranslationsForProperty($propertyName),
                    spl_object_id($entity) . '.' . $propertyName
                );
                $texts->add($textForProperty);
            }
        }
        $translatedTexts = $this->translateTexts($texts);
        $entitiesWithTranslations = new EntitySet();

        //Translatable::setTranslationSettingsSnapshot();
        //Translatable::setCurrentLanguageCode(Translatable::getDefaultLanguageCode());
        // We unset Country Code in order to have no default country code stored
        //Translatable::setCurrentCountryCode(null);
        foreach ($translatedTexts->getElements() as $translatedText) {
            $externalIdExploded = explode('.', $translatedText->externalId);
            /** @var TranslatableTrait $entityClass */
            $entityId = $externalIdExploded[0];
            $propertyName = $externalIdExploded[1];
            $entity = $entitiesById[$entityId];
            if (!isset($entity)) {
                continue;
            }
            /** @var TranslatableTrait $entity */
            foreach ($translatedText->translations->getElements() as $translation) {
                if (!$translation->content){
                    continue;
                }
                $entity->setTranslationForProperty(
                    $propertyName,
                    $translation->content,
                    languageCode: $translation->locale->languageCode,
                    countryCode: null,
                    writingStyle: $translation->writingStyle == Text::WRITING_STYLE_FORMAL?Translatable::WRITING_STYLE_FORMAL:Translatable::WRITING_STYLE_INFORMAL
                );
            }
        }
        //Translatable::restoreTranslationSettingsSnapshot();
        if ($storeAutomatically) {
            foreach($entitySet->getElements() as $entity) {
                $entity->update();
            }
        }
        return $entitySet;
    }

    /**
     * Returns a Text entity with locale set based on a Translatable's translation array
     * @param Locales $localesToTranslate
     * @param array|null $translations
     * @param string|null $externalId
     * @param bool $forceRetranslation
     * @return Text|null
     */
    public function getTextToTranslateFromTranslationsArray(
        Locales $localesToTranslate,
        ?array $translations = null,
        string $externalId = null,
        bool $forceRetranslation = false
    ): ?Text {
        // nothing to do here
        if (!$translations) {
            return null;
        }
        $text = new Text();
        $text->externalId = $externalId;
        $localesAreMissing = false;
        $languageCodesToTranslate = $localesToTranslate->getLanguageCodes();
        $missingLocales = new Locales();
        foreach ($localesToTranslate->getElements() as $localeToTranslate) {
            if (!isset($translations[$localeToTranslate->languageCode . '::' . \DDD\Domain\Base\Entities\Translatable\Translatable::WRITING_STYLE_FORMAL]) || $forceRetranslation) {
                $localesAreMissing = true;
                $text->addLocaleToTranslate(locale: $localeToTranslate);
            }
        }
        if (!$localesAreMissing) {
            // all is translated already
            return null;
        }

        if (isset($translations[Translatable::getDefaultLanguageCode() . '::' . Translatable::WRITING_STYLE_FORMAL])) {
            $text->locale = new Locale(
                Translatable::getDefaultLanguageCode(), Config::get('Common.Political.Locales.defaultCountryCodesForLanguage')[Translatable::getDefaultLanguageCode()]
            );
            $text->content = $translations[Translatable::getDefaultLanguageCode() . '::' . Translatable::WRITING_STYLE_FORMAL];
        } elseif (isset($translations['de::' . Translatable::WRITING_STYLE_FORMAL])) {
            $text->locale = new Locale('de', Config::get('Common.Political.Locales.defaultCountryCodesForLanguage')['de']);
            $text->content = $translations['de::' . Translatable::WRITING_STYLE_FORMAL];
        } else {
            $firstKey = key($translations);
            $languageCode = substr($firstKey, 0, 2);
            $countryCode = Config::get('Common.Political.Locales.defaultCountryCodesForLanguage')[$languageCode] ?? null;
            $text->locale = new Locale($languageCode, $countryCode);
            $text->content = $translations[$languageCode . '::' . Translatable::WRITING_STYLE_FORMAL];
        }
        return $text;
    }

    /**
     * Translates an instance of Texts using the Argus AI layer.
     *
     * Texts are grouped by locale+writingStyle into buckets. Large texts that exceed
     * the model's output token capacity are split into chunks (with `.chunk.N` suffixes).
     * Buckets are further split when the cumulative estimated token count exceeds the
     * model's output limit, so each Argus call stays within capacity. After translation,
     * chunked results are reassembled back into the original Text objects.
     *
     * @param Texts $texts
     * @return Texts
     * @throws GuzzleException
     */
    public function translateTexts(Texts $texts): Texts
    {
        $textsBuckets = new TextsBuckets();
        /** @var AIPromptsService $aiPromptsService */
        $aiPromptsService = DDDService::instance()->getService(AIPromptsService::class);

        $maxCharsPerBucket = self::MAX_CHARS_PER_BUCKET;

        // Running state per locale+writingStyle: current bucket + accumulated char count
        $currentBucketByKey = [];
        $charCountByKey = [];
        $bucketCounterByKey = [];

        foreach ($texts->getElements() as $text) {
            foreach ($text->translations->getElements() as $translation) {
                $locale = $translation->locale;
                $writingStyle = $translation->writingStyle;
                $localeWritingStyleKey = $locale . '::' . $writingStyle;

                // Ensure we have a current bucket for this key
                if (!isset($currentBucketByKey[$localeWritingStyleKey])) {
                    $bucketCounterByKey[$localeWritingStyleKey] = 0;
                    $currentBucketByKey[$localeWritingStyleKey] = $this->createBucketForLocale(
                        $locale,
                        $writingStyle,
                        $localeWritingStyleKey,
                        $bucketCounterByKey[$localeWritingStyleKey],
                        $aiPromptsService
                    );
                    $charCountByKey[$localeWritingStyleKey] = 0;
                    $textsBuckets->add($currentBucketByKey[$localeWritingStyleKey]);
                }

                $contentLength = strlen($text->content ?? '');

                // If a single text exceeds the char limit, chunk it
                if ($contentLength > $maxCharsPerBucket) {
                    // Flush current bucket if it has content, start fresh
                    if ($charCountByKey[$localeWritingStyleKey] > 0) {
                        $bucketCounterByKey[$localeWritingStyleKey]++;
                        $currentBucketByKey[$localeWritingStyleKey] = $this->createBucketForLocale(
                            $locale,
                            $writingStyle,
                            $localeWritingStyleKey,
                            $bucketCounterByKey[$localeWritingStyleKey],
                            $aiPromptsService
                        );
                        $charCountByKey[$localeWritingStyleKey] = 0;
                        $textsBuckets->add($currentBucketByKey[$localeWritingStyleKey]);
                    }

                    $chunks = $this->chunkText($text->content, $maxCharsPerBucket);

                    foreach ($chunks as $chunkIndex => $chunk) {
                        $chunkedText = new Text();
                        $chunkedText->content = $chunk;
                        $chunkedText->externalId = $text->externalId . '.chunk.' . $chunkIndex;
                        $chunkedText->writingStyle = $writingStyle;
                        $currentBucketByKey[$localeWritingStyleKey]->add($chunkedText);

                        // Each chunk goes into its own bucket to stay within limits
                        $bucketCounterByKey[$localeWritingStyleKey]++;
                        $currentBucketByKey[$localeWritingStyleKey] = $this->createBucketForLocale(
                            $locale,
                            $writingStyle,
                            $localeWritingStyleKey,
                            $bucketCounterByKey[$localeWritingStyleKey],
                            $aiPromptsService
                        );
                        $charCountByKey[$localeWritingStyleKey] = 0;
                        $textsBuckets->add($currentBucketByKey[$localeWritingStyleKey]);
                    }
                } else {
                    // Check if adding this text would exceed the batch char limit
                    if ($charCountByKey[$localeWritingStyleKey] + $contentLength > $maxCharsPerBucket) {
                        // Start a new bucket
                        $bucketCounterByKey[$localeWritingStyleKey]++;
                        $currentBucketByKey[$localeWritingStyleKey] = $this->createBucketForLocale(
                            $locale,
                            $writingStyle,
                            $localeWritingStyleKey,
                            $bucketCounterByKey[$localeWritingStyleKey],
                            $aiPromptsService
                        );
                        $charCountByKey[$localeWritingStyleKey] = 0;
                        $textsBuckets->add($currentBucketByKey[$localeWritingStyleKey]);
                    }

                    $textForLocale = new Text();
                    $textForLocale->content = $text->content;
                    $textForLocale->externalId = $text->externalId;
                    $textForLocale->writingStyle = $writingStyle;
                    $currentBucketByKey[$localeWritingStyleKey]->add($textForLocale);
                    $charCountByKey[$localeWritingStyleKey] += $contentLength;
                }
            }
        }

        // Remove empty buckets (can happen after chunking creates a trailing empty bucket)
        $nonEmptyBuckets = new TextsBuckets();
        foreach ($textsBuckets->getElements() as $bucket) {
            if ($bucket->count() > 0) {
                $nonEmptyBuckets->add($bucket);
            }
        }

        // Execute all buckets in parallel via Argus
        $argusTextsBuckets = new ArgusTextsBuckets();
        $argusTextsBuckets->fromEntity($nonEmptyBuckets);
        $argusTextsBuckets->setPropertiesToLoad(ArgusTranslations::class);
        $argusTextsBuckets->argusLoad(useArgusEntityCache: false, useApiACallCache: false);

        // Collect all translations, handling chunk reassembly
        // Structure: $chunkedResults[locale::writingStyle][originalExternalId][chunkIndex] = translatedText
        $chunkedResults = [];

        foreach ($argusTextsBuckets->getElements() as $textsForLocale) {
            foreach ($textsForLocale->getTranslations()->getElements() as $translation) {
                $externalId = $translation->externalId;
                $localeStr = (string)$translation->locale;
                $writingStyle = $translation->writingStyle;
                $localeWritingStyleKey = $localeStr . '::' . $writingStyle;

                // Check if this is a chunked translation
                $chunkCharIndex = strpos($externalId, '.chunk.');
                if ($chunkCharIndex !== false) {
                    $originalExternalId = substr($externalId, 0, $chunkCharIndex);
                    $chunkIndex = (int)substr($externalId, $chunkCharIndex + 7);

                    if (!isset($chunkedResults[$localeWritingStyleKey])) {
                        $chunkedResults[$localeWritingStyleKey] = [];
                    }
                    if (!isset($chunkedResults[$localeWritingStyleKey][$originalExternalId])) {
                        $chunkedResults[$localeWritingStyleKey][$originalExternalId] = [];
                    }
                    $chunkedResults[$localeWritingStyleKey][$originalExternalId][$chunkIndex] = $translation->content;
                } else {
                    // Non-chunked: apply directly
                    $text = $texts->getByExternalId($externalId);
                    if (!$text) {
                        continue;
                    }
                    $originalTranslationInstance = $text->translations->getTranslationForParameters(
                        externalId: $text->externalId,
                        locale: $translation->locale,
                        writingStyle: $translation->writingStyle
                    );
                    if ($originalTranslationInstance) {
                        $originalTranslationInstance->content = $translation->content;
                    }
                }
            }
        }

        // Reassemble chunked translations
        foreach ($chunkedResults as $localeWritingStyleKey => $translationsByExternalId) {
            [$localeStr, $writingStyle] = explode('::', $localeWritingStyleKey);
            $locale = Locale::fromString($localeStr);

            foreach ($translationsByExternalId as $originalExternalId => $chunks) {
                ksort($chunks);
                $reassembledContent = implode('', $chunks);

                $text = $texts->getByExternalId($originalExternalId);
                if (!$text) {
                    continue;
                }
                $originalTranslationInstance = $text->translations->getTranslationForParameters(
                    locale: $locale,
                    writingStyle: $writingStyle
                );
                if ($originalTranslationInstance) {
                    $originalTranslationInstance->content = $reassembledContent;
                }
            }
        }

        return $texts;
    }

    /**
     * Creates a new Texts bucket configured for a specific locale and writing style.
     *
     * @param Locale $locale
     * @param string $writingStyle
     * @param string $localeWritingStyleKey
     * @param int $bucketIndex
     * @param AIPromptsService $aiPromptsService
     * @return Texts
     */
    protected function createBucketForLocale(
        Locale $locale,
        string $writingStyle,
        string $localeWritingStyleKey,
        int $bucketIndex,
        AIPromptsService $aiPromptsService
    ): Texts {
        $textsForLocale = new Texts();
        $textsForLocale->identifier = $localeWritingStyleKey . '::' . $bucketIndex;
        $textsForLocale->addLocaleToTranslateAtOnce($locale);
        $textsForLocale->getTranslations()->defaultWritingStyle = $writingStyle;
        $textsForLocale->defaultWritingStyle = $writingStyle;
        $aiPromptName = TranslationPrompts::APP_TRANSLATIONS_SINGLE_LOCALE_INFORMAL;
        $translationsAIPrompt = $aiPromptsService->getAIPromptByName($aiPromptName);
        $translationsAIPrompt->setParameter(
            'default_locale',
            $locale->languageCode . '-' . $locale->countryShortCode
        );
        $textsForLocale->getTranslations()->translationsAIPrompt = $translationsAIPrompt;

        return $textsForLocale;
    }

    /**
     * Chunk text into pieces, choosing a Markdown‐aware splitter when needed.
     *
     * @param string $text
     * @param int $maxChars
     * @return string[]
     */
    public function chunkText(string $text, int $maxChars): array
    {
        if ($this->isMarkdown($text)) {
            return $this->chunkMarkdownAware($text, $maxChars);
        }

        return $this->chunkTextRegex($text, $maxChars);
    }

    /**
     * Check if text is markdown formatted.
     *
     * @param string $text
     * @return bool
     */
    public function isMarkdown(string $text): bool
    {
        return (bool)preg_match(
            '/(^#{1,6}\s)|(^[-*+]\s)|(```)|($begin:math:display$[^$end:math:display$]+\]$begin:math:text$[^)]+$end:math:text$)/m',
            $text
        );
    }

    /**
     * Split markdown text into chunks, treating fenced code blocks as atomic.
     *
     * @param string $text
     * @param int $maxChars
     * @return string[]
     */
    public function chunkMarkdownAware(string $text, int $maxChars): array
    {
        // capture fenced code blocks (```…```)
        $pattern = '/(```.*?```)/s';
        // split into code vs. everything else
        $parts = preg_split($pattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $chunks = [];

        foreach ($parts as $part) {
            if (preg_match($pattern, $part)) {
                // fenced code block – keep intact or hard‐cut
                if (strlen($part) <= $maxChars) {
                    $chunks[] = $part;
                } else {
                    foreach (str_split($part, $maxChars) as $frag) {
                        $chunks[] = $frag;
                    }
                }
            } else {
                // normal markdown text – sentence‐split
                $chunks = array_merge($chunks, $this->chunkTextRegex($part, $maxChars));
            }
        }

        return $chunks;
    }

    /**
     * Split text into chunks with regex-based sentence splitting.
     *
     * @param string $text
     * @param int $maxChars
     * @return string[]
     */
    public function chunkTextRegex(string $text, int $maxChars): array
    {
        $sentences = preg_split('/(?<=[.?!])\s+(?=[A-Z])/u', $text);
        $chunks = [];
        $current = '';

        foreach ($sentences as $s) {
            $sentence = trim($s);

            // if adding this sentence busts the cap, flush current buffer
            if (strlen($current) + strlen($sentence) > $maxChars) {
                if ($current !== '') {
                    $chunks[] = trim($current);
                    $current = '';
                }
                // if the single sentence is itself too long, hard‐cut it
                if (strlen($sentence) > $maxChars) {
                    foreach (str_split($sentence, $maxChars) as $part) {
                        $chunks[] = trim($part);
                    }
                    continue;
                }
            }

            // accumulate
            $current .= ($current === '' ? '' : ' ') . $sentence;
        }

        if (trim($current) !== '') {
            $chunks[] = trim($current);
        }

        return $chunks;
    }

}
