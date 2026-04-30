<?php

namespace DDD\Domain\Common\Services\Translations;

use DDD\Domain\AI\Services\AIPromptsService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Entities\Translatable\TranslatableTrait;
use DDD\Domain\Common\Entities\Locales\Locale;
use DDD\Domain\Common\Entities\Locales\Locales;
use DDD\Domain\Common\Entities\Texts\Buckets\TextsBuckets;
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Common\Entities\Texts\Texts;
use DDD\Domain\Common\Entities\Texts\TranslationPrompts;
use DDD\Domain\Common\Repo\Argus\Texts\Buckets\ArgusTextsBuckets;
use DDD\Domain\Common\Repo\Argus\Texts\Translations\ArgusTranslations;
use DDD\Infrastructure\Libs\Config;
use DDD\Infrastructure\Reflection\ReflectionClass;
use DDD\Infrastructure\Services\DDDService;
use GuzzleHttp\Exception\GuzzleException;
use ReflectionException;

class AITranslationsService
{
    /**
     * Maximum cumulative character count per translation bucket before splitting into a new batch.
     *
     * Kept intentionally small (1000 chars ≈ 250 tokens out, ~5–8 s per Argus call even for
     * Slavic languages that tokenize denser) so a long document is split into many independent
     * chunks that translate in parallel — the design intent of this service.
     *
     * Cross-chunk terminology consistency is handled at the prompt layer (UI label table +
     * glossary in the Entity prompt), not by force-fitting whole documents into one bucket.
     * Structural integrity across chunks is handled by the hierarchical chunker, which splits
     * at H2/H3/paragraph boundaries and reassembles via implode('') byte-for-byte.
     */
    protected const int MAX_CHARS_PER_BUCKET = 3000;

    /**
     * Maximum number of attempts (initial + retries) to obtain translations for every submitted
     * chunk. The LLM occasionally drops IDs from its response (rate-limit, malformed JSON, model
     * skip) — without a retry, those drops would silently produce a translated document with
     * mid-text holes. After this many attempts, missing chunks raise a RuntimeException so the
     * caller never deploys a stealth-truncated translation.
     */
    protected const int MAX_TRANSLATION_ATTEMPTS = 4;

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
    ): EntitySet {
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
                if (!$translation->content) {
                    continue;
                }
                $entity->setTranslationForProperty(
                    $propertyName,
                    $translation->content,
                    languageCode: $translation->locale->languageCode,
                    countryCode: null,
                    writingStyle: $translation->writingStyle == Text::WRITING_STYLE_FORMAL ? Translatable::WRITING_STYLE_FORMAL : Translatable::WRITING_STYLE_INFORMAL
                );
            }
        }
        //Translatable::restoreTranslationSettingsSnapshot();
        if ($storeAutomatically) {
            foreach ($entitySet->getElements() as $entity) {
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
            if (!isset($translations[$localeToTranslate->languageCode . '::' . Translatable::WRITING_STYLE_FORMAL]) || $forceRetranslation) {
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
                Translatable::getDefaultLanguageCode(),
                Config::get('Common.Political.Locales.defaultCountryCodesForLanguage')[Translatable::getDefaultLanguageCode()]
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
        /** @var AIPromptsService $aiPromptsService */
        $aiPromptsService = DDDService::instance()->getService(AIPromptsService::class);
        $maxCharsPerBucket = self::MAX_CHARS_PER_BUCKET;

        // ─── 1) Build initial buckets + record what we expect back ────────────────────────
        // $expectedChunks tracks every (localeKey, externalId) pair we send to the LLM.
        // We need the source content + writingStyle so we can rebuild fresh Text objects on retry.
        // Format: $expectedChunks[localeKey][externalId] = ['content' => str, 'writingStyle' => str]
        $expectedChunks = [];

        $textsBuckets = new TextsBuckets();
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

                if ($contentLength > $maxCharsPerBucket) {
                    // Chunk the text. Chunks are packed into buckets like ordinary texts — multiple
                    // small chunks can share one bucket (better terminology consistency for the LLM)
                    // and a single bucket never exceeds maxCharsPerBucket.
                    $chunks = $this->chunkText($text->content, $maxCharsPerBucket);

                    foreach ($chunks as $chunkIndex => $chunk) {
                        $chunkLength = strlen($chunk);

                        if ($charCountByKey[$localeWritingStyleKey] > 0
                            && $charCountByKey[$localeWritingStyleKey] + $chunkLength > $maxCharsPerBucket) {
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

                        $chunkExternalId = $text->externalId . '.chunk.' . $chunkIndex;
                        $chunkedText = new Text();
                        $chunkedText->content = $chunk;
                        $chunkedText->externalId = $chunkExternalId;
                        $chunkedText->writingStyle = $writingStyle;
                        $currentBucketByKey[$localeWritingStyleKey]->add($chunkedText);
                        $charCountByKey[$localeWritingStyleKey] += $chunkLength;

                        $expectedChunks[$localeWritingStyleKey][$chunkExternalId] = [
                            'content'      => $chunk,
                            'writingStyle' => $writingStyle,
                        ];
                    }
                } else {
                    if ($charCountByKey[$localeWritingStyleKey] + $contentLength > $maxCharsPerBucket) {
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

                    $expectedChunks[$localeWritingStyleKey][$text->externalId] = [
                        'content'      => $text->content ?? '',
                        'writingStyle' => $writingStyle,
                    ];
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

        // ─── 2) Run argusLoad + retry-on-missing loop ─────────────────────────────────────
        // The LLM occasionally drops IDs from its response (rate-limit, model skip, malformed
        // JSON). Without retry, those drops would silently produce a translated document with
        // mid-text holes. We track which (localeKey, externalId) we're still waiting for, and
        // re-issue smaller buckets for just the missing ones, up to MAX_TRANSLATION_ATTEMPTS.
        $chunkedResults = [];   // [localeKey][originalExternalId][chunkIndex] = content
        $stillMissing = $expectedChunks;
        $bucketsToProcess = $nonEmptyBuckets;

        for ($attempt = 1; $attempt <= self::MAX_TRANSLATION_ATTEMPTS; $attempt++) {
            if ($bucketsToProcess->count() === 0) {
                break;
            }

            $argusTextsBuckets = new ArgusTextsBuckets();
            $argusTextsBuckets->fromEntity($bucketsToProcess);
            $argusTextsBuckets->setPropertiesToLoad(ArgusTranslations::class);
            $argusTextsBuckets->argusLoad(useArgusEntityCache: false, useApiACallCache: false);

            foreach ($argusTextsBuckets->getElements() as $textsForLocale) {
                foreach ($textsForLocale->getTranslations()->getElements() as $translation) {
                    $externalId = $translation->externalId;
                    $localeStr = (string)$translation->locale;
                    $writingStyle = $translation->writingStyle;
                    $localeWritingStyleKey = $localeStr . '::' . $writingStyle;

                    // Mark as received — clears it from the still-missing set so retry passes
                    // don't ask for it again.
                    unset($stillMissing[$localeWritingStyleKey][$externalId]);
                    if (isset($stillMissing[$localeWritingStyleKey])
                        && empty($stillMissing[$localeWritingStyleKey])) {
                        unset($stillMissing[$localeWritingStyleKey]);
                    }

                    // Store result. Chunked vs non-chunked is determined by the externalId suffix.
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
                        // Non-chunked: apply directly to the original Text's translation slot.
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

            if (empty($stillMissing)) {
                break;
            }
            if ($attempt === self::MAX_TRANSLATION_ATTEMPTS) {
                break;
            }
            $bucketsToProcess = $this->buildBucketsForRetry($stillMissing, $aiPromptsService, $maxCharsPerBucket);
        }

        // ─── 3) Reassemble chunked translations ────────────────────────────────────────────
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

        // ─── 4) Surface incomplete translations ────────────────────────────────────────────
        // After exhausting retries, anything still missing means the LLM kept dropping that ID.
        // Throwing here is preferable to silently returning a translation with mid-document
        // holes — the caller decides whether to retry the whole call, mark the entity as
        // partially translated, or surface to the user.
        if (!empty($stillMissing)) {
            $missingCount = 0;
            $sampleIds = [];
            foreach ($stillMissing as $localeKey => $byExternalId) {
                $missingCount += count($byExternalId);
                foreach (array_keys($byExternalId) as $extId) {
                    if (count($sampleIds) < 5) {
                        $sampleIds[] = $localeKey . '/' . $extId;
                    }
                }
            }
            throw new \RuntimeException(sprintf(
                'AITranslationsService::translateTexts: %d chunk(s) still missing after %d attempts'
                . ' across %d locale-style combinations. Examples: %s',
                $missingCount,
                self::MAX_TRANSLATION_ATTEMPTS,
                count($stillMissing),
                implode(', ', $sampleIds)
            ));
        }

        return $texts;
    }

    /**
     * Re-pack still-missing chunks into a fresh TextsBuckets for another argusLoad pass.
     *
     * Creates new Text objects (not reusing the originals submitted in the previous attempt)
     * so the retry pipeline sees a clean entity set without any leftover state from the
     * previous Argus call. The bucket-packing is the same locale-grouped char-budgeted logic
     * as the initial pass, just operating on a much smaller input set.
     *
     * @param array<string, array<string, array{content: string, writingStyle: string}>> $stillMissing
     *   Map of [localeWritingStyleKey][externalId] = ['content' => ..., 'writingStyle' => ...].
     *   localeWritingStyleKey has the form "{locale}::{writingStyle}", produced by
     *   `(string)$locale . '::' . $writingStyle` matching the format used by translateTexts.
     * @return TextsBuckets
     */
    protected function buildBucketsForRetry(
        array $stillMissing,
        AIPromptsService $aiPromptsService,
        int $maxCharsPerBucket
    ): TextsBuckets {
        $buckets = new TextsBuckets();
        $currentBucketByKey = [];
        $charCountByKey = [];
        $bucketCounterByKey = [];

        foreach ($stillMissing as $localeWritingStyleKey => $byExternalId) {
            // Recover the Locale object from the composite key.
            $separatorPos = strrpos($localeWritingStyleKey, '::');
            if ($separatorPos === false) {
                continue;
            }
            $localeStr = substr($localeWritingStyleKey, 0, $separatorPos);
            $writingStyle = substr($localeWritingStyleKey, $separatorPos + 2);
            $locale = Locale::fromString($localeStr);

            foreach ($byExternalId as $externalId => $chunkInfo) {
                $contentLength = strlen($chunkInfo['content'] ?? '');

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
                    $buckets->add($currentBucketByKey[$localeWritingStyleKey]);
                }

                if ($charCountByKey[$localeWritingStyleKey] > 0
                    && $charCountByKey[$localeWritingStyleKey] + $contentLength > $maxCharsPerBucket) {
                    $bucketCounterByKey[$localeWritingStyleKey]++;
                    $currentBucketByKey[$localeWritingStyleKey] = $this->createBucketForLocale(
                        $locale,
                        $writingStyle,
                        $localeWritingStyleKey,
                        $bucketCounterByKey[$localeWritingStyleKey],
                        $aiPromptsService
                    );
                    $charCountByKey[$localeWritingStyleKey] = 0;
                    $buckets->add($currentBucketByKey[$localeWritingStyleKey]);
                }

                $retryText = new Text();
                $retryText->content = $chunkInfo['content'];
                $retryText->externalId = $externalId;
                $retryText->writingStyle = $chunkInfo['writingStyle'];
                $currentBucketByKey[$localeWritingStyleKey]->add($retryText);
                $charCountByKey[$localeWritingStyleKey] += $contentLength;
            }
        }

        return $buckets;
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

        // AITranslationsService translates entity-property content (markdown FAQs, descriptions,
        // chat/support messages) — distinct from AppTranslationsService which translates short UI
        // strings. The Entity prompt explicitly preserves markdown/whitespace structure and uses
        // the {externalId, content, originalLocale} input shape that ArgusTranslations emits.
        $aiPromptName = TranslationPrompts::ENTITY_TRANSLATABLE;
        $translationsAIPrompt = $aiPromptsService->getAIPromptByName($aiPromptName);
        $localeString = $locale->languageCode . '-' . $locale->countryShortCode;
        // Set both placeholder names so a future swap to an App-style prompt still resolves.
        $translationsAIPrompt
            ->setParameter('targetLocale', $localeString)
            ->setParameter('default_locale', $localeString);
        $textsForLocale->getTranslations()->translationsAIPrompt = $translationsAIPrompt;

        return $textsForLocale;
    }

    /**
     * Chunk text into pieces no larger than $maxChars.
     *
     * Splits at the strongest available semantic boundary (heading > paragraph > line > sentence)
     * and PRESERVES the original separators (newlines, blank lines, indentation) inside each chunk.
     * This means concatenating the returned chunks via `implode('', $chunks)` reproduces the original
     * input byte-for-byte — which is the contract the reassembly logic in translateTexts() relies on.
     *
     * The previous implementation rebuilt chunks by `trim()`ing each sentence and rejoining with a
     * single space, which destroyed every newline, blank line and list indentation in markdown
     * documents. That caused translated FAQs to render with answers glued onto heading lines,
     * paragraphs merged into one line, etc. — fixed here by routing markdown through a hierarchical
     * splitter that never strips whitespace.
     *
     * @param string $text
     * @param int $maxChars
     * @return string[]
     */
    public function chunkText(string $text, int $maxChars): array
    {
        if (strlen($text) <= $maxChars) {
            return [$text];
        }

        if ($this->isMarkdown($text)) {
            return $this->chunkMarkdownAware($text, $maxChars);
        }

        return $this->chunkTextRegex($text, $maxChars);
    }

    /**
     * Heuristic markdown detection. Looks for ATX headings, list bullets, fenced code blocks,
     * or `[label](target)` links anywhere in the text.
     *
     * The previous regex contained `$begin:math:display$…$end:math:display$` artifacts (LaTeX-style
     * substitution leftovers) where the markdown link pattern `\[[^\]]+\]\([^)]+\)` should have
     * been — restored here.
     *
     * @param string $text
     * @return bool
     */
    public function isMarkdown(string $text): bool
    {
        return (bool)preg_match(
            '/(^#{1,6}\s)|(^[-*+]\s)|(```)|(\[[^\]]+\]\([^)]+\))/m',
            $text
        );
    }

    /**
     * Markdown-aware chunker. Treats fenced code blocks (```…```) as atomic units and runs the
     * surrounding prose through a structure-preserving hierarchical splitter (see
     * splitProseHierarchically). Each returned chunk carries its own trailing separators, so
     * reassembly is lossless.
     *
     * @param string $text
     * @param int $maxChars
     * @return string[]
     */
    public function chunkMarkdownAware(string $text, int $maxChars): array
    {
        $codeFencePattern = '/(```.*?```)/s';
        $segments = preg_split($codeFencePattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);

        $chunks = [];
        $current = '';

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            $isCodeFence = (bool)preg_match($codeFencePattern, $segment);

            if ($isCodeFence) {
                // Code fence: keep intact unless larger than maxChars (rare).
                if ($current !== '' && strlen($current) + strlen($segment) > $maxChars) {
                    $chunks[] = $current;
                    $current = '';
                }
                if (strlen($segment) <= $maxChars) {
                    $current .= $segment;
                } else {
                    if ($current !== '') {
                        $chunks[] = $current;
                        $current = '';
                    }
                    // mb_str_split: cuts at codepoint boundaries. str_split would slice by bytes
                    // and break multi-byte UTF-8 sequences (e.g. curly quotes, em dashes), producing
                    // partial codepoints that later cause json_encode to return false in
                    // ArgusTranslations::getUserContent.
                    foreach (mb_str_split($segment, $maxChars, 'UTF-8') as $frag) {
                        $chunks[] = $frag;
                    }
                }
                continue;
            }

            // Prose between code fences: split hierarchically while keeping all whitespace.
            $proseChunks = $this->splitProseHierarchically($segment, $maxChars);
            foreach ($proseChunks as $proseChunk) {
                if ($current !== '' && strlen($current) + strlen($proseChunk) > $maxChars) {
                    $chunks[] = $current;
                    $current = '';
                }
                $current .= $proseChunk;
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * Split prose at the strongest semantic boundary that yields more than one part.
     *
     * Tries heading levels first (H1 → H2 → H3 → H4 → H5), then paragraph breaks (blank lines),
     * then single newlines, then sentence boundaries as a last resort. Each returned part still
     * contains its trailing separator (newline/blank line) so concatenation is lossless.
     *
     * @param string $text
     * @param int $maxChars
     * @return string[]
     */
    protected function splitProseHierarchically(string $text, int $maxChars): array
    {
        if (strlen($text) <= $maxChars) {
            return [$text];
        }

        // Heading-level splits — lookahead anchors before the heading line, so the heading
        // text plus its leading newline ends up at the start of the next part.
        $headingPatterns = [
            '/(?=^#\s)/m',
            '/(?=^##\s)/m',
            '/(?=^###\s)/m',
            '/(?=^####\s)/m',
            '/(?=^#####\s)/m',
        ];
        foreach ($headingPatterns as $pattern) {
            $parts = preg_split($pattern, $text);
            $nonEmpty = array_values(array_filter($parts, static fn(string $p): bool => $p !== ''));
            if (count($nonEmpty) > 1) {
                return $this->packParts($nonEmpty, $maxChars);
            }
        }

        // Paragraph breaks: \n optionally followed by spaces/tabs, then \n.
        $merged = $this->splitAndMerge($text, '/(\n[ \t]*\n)/');
        if (count($merged) > 1) {
            return $this->packParts($merged, $maxChars);
        }

        // Single newlines as a weaker boundary (covers list items, hard-wrapped lines).
        $merged = $this->splitAndMerge($text, '/(\n)/');
        if (count($merged) > 1) {
            return $this->packParts($merged, $maxChars);
        }

        // No structural boundaries left — fall back to sentence-level splitting.
        return $this->chunkTextRegex($text, $maxChars);
    }

    /**
     * Run a separator-capturing preg_split, merge each content with its trailing separator
     * via mergeWithSeparators, and discard empty parts. Returning fewer than two non-empty
     * parts means the split did not actually divide the text — common when the separator
     * sits at the very start or end (e.g. text ending in "\n\n"). Callers use the count to
     * decide whether to fall through to a weaker boundary, which prevents the recursion
     * from looping forever on a text that "splits" to itself plus an empty tail.
     *
     * @param string $text
     * @param string $separatorPattern Regex with one capturing group around the separator.
     * @return string[] Self-contained, non-empty parts (each carries its trailing separator).
     */
    protected function splitAndMerge(string $text, string $separatorPattern): array
    {
        $parts = preg_split($separatorPattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false || count($parts) <= 1) {
            return [$text];
        }
        $merged = $this->mergeWithSeparators($parts);
        return array_values(array_filter($merged, static fn(string $p): bool => $p !== ''));
    }

    /**
     * Take a preg_split(PREG_SPLIT_DELIM_CAPTURE) result of the form
     * [content, separator, content, separator, …, content] and merge each content element with
     * the separator that follows it. The result is an array where every element is self-contained:
     * concatenating them all reproduces the original string exactly.
     *
     * @param string[] $parts
     * @return string[]
     */
    protected function mergeWithSeparators(array $parts): array
    {
        $merged = [];
        $count = count($parts);
        for ($i = 0; $i < $count; $i += 2) {
            $merged[] = $parts[$i] . ($parts[$i + 1] ?? '');
        }
        return $merged;
    }

    /**
     * Pack a list of self-contained parts (each already includes its trailing separator) into
     * chunks no larger than $maxChars. If a single part on its own exceeds $maxChars, it is
     * recursively re-split via splitProseHierarchically — which means a giant H2 section will
     * cascade down to H3 / paragraph / line splits without ever losing whitespace.
     *
     * @param string[] $parts
     * @param int $maxChars
     * @return string[]
     */
    protected function packParts(array $parts, int $maxChars): array
    {
        $chunks = [];
        $current = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (strlen($part) > $maxChars) {
                if ($current !== '') {
                    $chunks[] = $current;
                    $current = '';
                }
                $chunks = array_merge($chunks, $this->splitProseHierarchically($part, $maxChars));
                continue;
            }
            if ($current !== '' && strlen($current) + strlen($part) > $maxChars) {
                $chunks[] = $current;
                $current = '';
            }
            $current .= $part;
        }
        if ($current !== '') {
            $chunks[] = $current;
        }
        return $chunks;
    }

    /**
     * Sentence-boundary fallback chunker. Captures the whitespace separator between sentences via
     * PREG_SPLIT_DELIM_CAPTURE so each sentence keeps its trailing newline/space — unlike the
     * previous trim-and-rejoin implementation which collapsed all whitespace into single spaces.
     *
     * @param string $text
     * @param int $maxChars
     * @return string[]
     */
    public function chunkTextRegex(string $text, int $maxChars): array
    {
        // Sentence-boundary regex extended for international text:
        //   - Terminators: . ? ! and U+2026 ellipsis (…)
        //   - Optional closing-quote/paren after the punctuation: " ' ) ] U+201D U+00BB
        //     (covers `."`, `?»`, `!)`, `."` for English/German/French close-quotes)
        //   - Required whitespace separator
        //   - Lookahead for the next sentence start: any Unicode uppercase letter (\p{Lu} —
        //     covers Cyrillic, Greek, Latin extended; not just ASCII A–Z), a digit (numbered
        //     lists "1." starting next sentence), or a bullet marker (- * U+2022).
        //   - Deliberately NOT matching a following lowercase letter, to avoid false positives
        //     on abbreviations like "U.S. economy" or "z.B. siehe" where the period isn't a
        //     sentence break.
        $sentenceBoundary = '/([.?!\x{2026}][\x{201D}\x{00BB}"\)\]]?\s+)(?=[\p{Lu}\d\-*\x{2022}])/u';
        $merged = $this->splitAndMerge($text, $sentenceBoundary);

        if (count($merged) > 1) {
            return $this->packParts($merged, $maxChars);
        }

        // No sentence boundary detected. If the text is only modestly oversized, prefer keeping
        // it whole over slicing it: a single ~25%-over-limit chunk is far better than a chunk
        // containing a sentence fragment. Only kicks in at the leaf (after every structural
        // split — heading, paragraph, line, sentence — has already been tried and rejected).
        $tolerance = (int)($maxChars * 0.25);
        if (mb_strlen($text, 'UTF-8') <= $maxChars + $tolerance) {
            return [$text];
        }

        // Still too long: try cutting at word boundaries (last whitespace before each maxChars
        // window) so we at least don't slice through a word.
        return $this->chunkAtWordBoundaries($text, $maxChars);
    }

    /**
     * Greedy word-boundary chunker.
     *
     * Walks $text in $maxChars-character windows, snapping each cut to the last whitespace
     * inside the window. If a window contains no whitespace at all (a long URL, a single
     * unbroken word, etc.), falls back to a codepoint-safe hard cut at exactly $maxChars
     * characters — the absolute last resort and the only point where we accept mid-word splits.
     *
     * Always cuts at codepoint boundaries (uses mb_substr / mb_strlen / preg_match with `/u`),
     * so no partial UTF-8 sequences ever leak out — that would otherwise crash json_encode in
     * ArgusTranslations::getUserContent.
     *
     * @param string $text
     * @param int $maxChars
     * @return string[]
     */
    protected function chunkAtWordBoundaries(string $text, int $maxChars): array
    {
        // Termination guard: a non-positive $maxChars would let the loop's "no-match" branch
        // set $cutLength = 0 and never advance $offset → infinite loop, hung process. The
        // current MAX_CHARS_PER_BUCKET constant rules this out, but a future programmatic
        // caller (or a test misconfiguring it) shouldn't be able to lock the worker.
        if ($maxChars < 1) {
            return [$text];
        }

        $chunks = [];
        $textLength = mb_strlen($text, 'UTF-8');
        $offset = 0;

        while ($offset < $textLength) {
            $remaining = $textLength - $offset;
            if ($remaining <= $maxChars) {
                $chunks[] = mb_substr($text, $offset, null, 'UTF-8');
                break;
            }

            $window = mb_substr($text, $offset, $maxChars, 'UTF-8');

            // Greedy match against everything up to the last whitespace inside the window.
            // The `s` flag lets `.` match newlines too (matters for paragraph-spanning prose).
            // If the window has no whitespace at all, preg_match returns 0 and we fall back
            // to a codepoint-safe hard cut at $maxChars.
            if (preg_match('/^(.*)\s\S*$/su', $window, $matches)) {
                // +1 codepoint to also consume the whitespace itself, keeping the next chunk's
                // text from starting with a stray leading space.
                $cutLength = mb_strlen($matches[1], 'UTF-8') + 1;
            } else {
                $cutLength = $maxChars;
            }

            // Belt-and-braces: $cutLength must be ≥ 1 to guarantee progress. The branches
            // above all produce ≥ 1 when $maxChars ≥ 1 (which the guard enforces), but if a
            // future change breaks that invariant we want a hung process, not a quiet loop.
            if ($cutLength < 1) {
                $cutLength = 1;
            }

            $chunks[] = mb_substr($text, $offset, $cutLength, 'UTF-8');
            $offset += $cutLength;
        }

        return $chunks;
    }

}
