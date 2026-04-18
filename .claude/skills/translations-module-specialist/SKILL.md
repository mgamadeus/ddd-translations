---
name: translations-module-specialist
description: Work with entity translations, app UI translations, text embeddings, and language detection from the ddd-common-translations module. Use when translating entity properties, managing app translation keys, generating translations via AI, or working with text embeddings.
metadata:
  author: mgamadeus
  version: "1.0.0"
  module: mgamadeus/ddd-common-translations
---

# Translations Module Specialist

Entity translations, app UI translations, embeddings, and language detection.

> **Base patterns:** See core skills in `vendor/mgamadeus/ddd`. For AI integration, see `vendor/mgamadeus/ddd-ai`. For Argus repos, see `vendor/mgamadeus/ddd-argus`.

## When to Use

- Translating `#[Translatable]` entity properties to multiple locales
- Managing app UI translation keys and values
- Generating translations via AI (batch or async)
- Working with text embeddings
- Detecting languages of text content
- Tracking translation completeness per locale

## Entity Property Translation

### Translating `#[Translatable]` Properties

```php
use DDD\Domain\Common\Services\Translations\AITranslationsService;

/** @var AITranslationsService $aiTranslationsService */
$aiTranslationsService = AITranslationsService::getService();

// Translate all #[Translatable] properties on entities
$translatedEntities = $aiTranslationsService->translateEntities(
    entitySet: $products,
    localesToTranslate: $activeLocales,
    storeAutomatically: true,   // Call update() after translating
    async: false                // Or true for background processing
);
```

**Chunking:** Large texts are split automatically (max 1000 chars/bucket). Markdown-aware splitting preserves code blocks. Chunks are reassembled after translation.

### Text / Translation ValueObjects

```php
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Common\Entities\Texts\Texts;

// Create texts for translation
$text = new Text(
    content: 'Hello World',
    language: 'en',
    countryShortCode: 'US',
    writingStyle: Text::WRITING_STYLE_FORMAL,
    externalId: 'greeting_1'
);

$texts = new Texts();
$texts->add($text);
$texts->addLocaleToTranslateAtOnce($germanLocale);  // Add target locale

// Translate (sync or async)
$texts->translate(async: false);

// Access translations
foreach ($text->translations->getElements() as $translation) {
    echo $translation->locale->languageCode . ': ' . $translation->content;
}
```

### Writing Styles & Context

- **Writing styles:** `WRITING_STYLE_FORMAL`, `WRITING_STYLE_INFORMAL`
- **Context:** `CONTEXT_ONE` (singular), `CONTEXT_MANY` (plural)

## App UI Translations

### AppTranslationKey

```php
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationKey;

// Properties
$key->key;                              // 'Welcome to {appName}'
$key->translationTemplate;             // Optional: template overriding key for AI
$key->translationHint;                 // Optional: context for AI translator
$key->requiresContext;                 // true = needs singular/plural forms
$key->doNotTranslateAutomatically;     // Skip in batch AI translation
$key->reTranslate;                     // Mark for re-translation
$key->appTranslationValues;            // Lazy-loaded translations
```

### AppTranslationValue

```php
$value->appTranslationKeyId;           // FK to key
$value->languageId;                    // FK to Language
$value->countryId;                     // Optional: country-specific variant
$value->translation;                   // The translated string
$value->writingStyle;                  // FORMAL or INFORMAL
$value->context;                       // ONE or MANY
```

Unique index: `(appTranslationKeyId, languageId, virtualCountryId)` -- uses virtual column `IFNULL(countryId, 0)` for NULL-safe uniqueness.

### Generating Translations

```php
/** @var AppTranslationsService $appTranslationsService */
$appTranslationsService = AppTranslationsService::getService();

// Generate for a specific locale
$result = $appTranslationsService->generateAppTranslationsForLocale(
    locale: $germanLocale,
    writingStyle: 'FORMAL',
    previewOnly: false           // true = estimate costs only
);

// Generate for all active locales
$results = $appTranslationsService->generateAppTranslationsForActiveLocales(
    previewOnly: false
);

// Result contains: translatedKeysCount, apiCalls, estimatedCosts, translationAIModel
$results->calculateTotalCosts();
echo $results->totalCosts->amount;  // MoneyAmount
```

### Importing from Config

```php
$keysService = AppTranslationKeys::getService();
$result = $keysService->importFromTranslationsConfig();
// Reads from config/app/Common/Translations.php
// Returns: ['keysCreated' => N, 'valuesCreated' => N, ...]
```

### Completeness Tracking

```php
$keysService = AppTranslationKeys::getService();
$completenesses = $keysService->getCompletenessForActiveLocales();

foreach ($completenesses->getElements() as $completeness) {
    echo $completeness->locale->languageCode . ': '
        . $completeness->getInformalCompletenessPercent() . '% informal, '
        . $completeness->getFormalCompletenessPercent() . '% formal';
}
```

### AppTranslatableService (DB-Backed `__()`)

Override core `TranslatableService` to read from DB instead of config:

```yaml
# services.yaml
DDD\Domain\Base\Services\TranslatableService:
    class: DDD\Domain\Common\Services\Translations\AppTranslatableService
    public: true
```

Then `__('Welcome')` reads from `AppTranslationKey`/`AppTranslationValue` with APC caching (1h).

**Fallback chain:** exact match -> no country -> alt writing style -> default language -> first available -> key itself.

## Text Embeddings

```php
use DDD\Domain\Common\Services\Texts\Embeddings\AITextEmbeddingsService;

$embeddingsService = AITextEmbeddingsService::getService();
$embedding = $embeddingsService->generateEmbeddingForText($content, $dimensions);
// Returns TextEmbedding (Vector) via ArgusTextEmbedding
```

Models: `text-embedding-3-small` (default), `text-embedding-3-large`

## Language Detection

Lazy-loaded on Text entities via ArgusDetectedLanguage:

```php
$text = new Text(content: 'Bonjour le monde');
$detectedLanguage = $text->detectedLanguage;  // Lazy-loaded via Argus
echo $detectedLanguage->languageCode;         // 'fr'
```

Uses first 100 words for efficiency.

## Async Processing

```yaml
# messenger.yaml
framework:
    messenger:
        transports:
            app_translations: '%env(MESSENGER_TRANSPORT_DSN_RABBITMQ)%/app_translations'
        routing:
            'DDD\Domain\Common\MessageHandlers\AppTranslationsMessage': app_translations
```

```php
// Dispatch async translation
$texts->translate(async: true);
// Dispatches AppTranslationsMessage -> AppTranslationsHandler
```

## Prompt Templates

| Constant | Path | Purpose |
|----------|------|---------|
| `ENTITY_TRANSLATABLE` | `Common/Translations/Entity/Translatable.md` | Entity property translation |
| `APP_TRANSLATIONS_SINGLE_LOCALE_FORMAL` | `Common/Translations/AppTranslations/SingleLocaleFormal.md` | App UI formal |
| `APP_TRANSLATIONS_SINGLE_LOCALE_INFORMAL` | `Common/Translations/AppTranslations/SingleLocaleInformal.md` | App UI informal |
| `DETECTED_LANGUAGE` | `Common/Texts/DetectedLanguage.md` | Language detection |

Override by placing file at same path in your project's `config/app/AI/Prompts/`.

## Default Terms (Glossary)

`AppTranslationDefaultTerm` entities define consistent terminology per language:

```php
$termsService = AppTranslationDefaultTerms::getService();
$terms = $termsService->findDefaultTermsForLanguageCode('de');
echo $terms->getDefaultTermsAsStringPairs();
// "Einstellungen;Settings\nAbmelden;Logout\n"
```

These are injected into translation prompts for consistency.
