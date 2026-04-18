# mgamadeus/ddd-common-translations -- Translations Module

Texts, entity translations, app UI translations, embeddings, and language detection for the `mgamadeus/ddd` framework.

**Package:** `mgamadeus/ddd-common-translations` (v1.0.x)
**Namespace:** `DDD\`
**Depends on:** `mgamadeus/ddd` (^2.10), `mgamadeus/ddd-ai` (^1.0), `mgamadeus/ddd-common-money` (^1.0), `mgamadeus/ddd-common-political` (^1.0)

> **This module follows all DDD Core conventions.** For base patterns, see `vendor/mgamadeus/ddd/AGENTS.md` and skills in `vendor/mgamadeus/ddd`. For AI patterns, see `vendor/mgamadeus/ddd-ai`. For Argus patterns, see `vendor/mgamadeus/ddd-argus`.

## Architecture

```
src/Domain/Common/
+-- Entities/
|   +-- Texts/                         [Text, Texts, Translation, Translations, DetectedLanguage]
|   |   +-- Embeddings/TextEmbedding.php
|   |   +-- Translations/             [Translation, Translations]
|   |   +-- Buckets/TextsBuckets.php  [Batching container]
|   |   +-- TranslationPrompts.php    [Prompt name constants]
|   +-- AppTranslations/               [DB-backed app UI translations]
|       +-- AppTranslationKey.php      [Keys with templates, hints]
|       +-- AppTranslationValue.php    [Per-language/country/style values]
|       +-- AppTranslationDefaultTerm.php [Glossary terms]
|       +-- Completeness/             [Per-locale coverage metrics]
+-- Repo/
|   +-- Argus/Texts/                   [ArgusTranslation, ArgusTranslations, ArgusTextEmbedding, ArgusDetectedLanguage]
|   +-- DB/AppTranslations/            [Doctrine repos for AppTranslation* entities]
+-- Services/
|   +-- AppTranslations/               [AppTranslationsService, AppTranslatableService, AppTranslationKeysService, AppTranslationValuesService]
|   +-- Translations/AITranslationsService.php [Entity property translation]
|   +-- Texts/Embeddings/AITextEmbeddingsService.php
+-- MessageHandlers/                   [AppTranslationsHandler + AppTranslationsMessage]
config/app/AI/Prompts/Common/         [4 prompt templates]
```

## Two Translation Systems

### 1. Entity Property Translation (AITranslationsService)

Translates `#[Translatable]` properties on entities to multiple locales via AI:

```php
$aiTranslationsService = AITranslationsService::getService();
$aiTranslationsService->translateEntities($entitySet, $locales, storeAutomatically: true);
```

Features: automatic text chunking (max 1000 chars/bucket), Markdown-aware splitting, parallel Argus calls via TextsBuckets.

### 2. App UI Translation (AppTranslationsService)

DB-backed translation key/value system for application UI strings:

- **AppTranslationKey** -- keys with optional templates, hints, context flags (singular/plural)
- **AppTranslationValue** -- per language + country + writing style translations
- **AppTranslationDefaultTerm** -- glossary for consistent terminology
- **Completeness tracking** -- per-locale coverage (formal + informal percentages)

## Key Entities

| Entity | Type | Persisted | Purpose |
|--------|------|-----------|---------|
| **Text** | ValueObject | Argus cache | Base text with locale, writing style, context |
| **Translation** | ValueObject (extends Text) | Argus cache | Translated version of a Text |
| **TextEmbedding** | ValueObject | Argus cache | Vector representation via OpenAI embeddings |
| **DetectedLanguage** | ValueObject | Argus cache | AI-detected language code |
| **AppTranslationKey** | Entity | DB | UI translation key with metadata |
| **AppTranslationValue** | Entity | DB | Per-locale translation value |
| **AppTranslationDefaultTerm** | Entity | DB | Glossary/terminology mapping |

## Async Translation

Via Symfony Messenger (`app_translations` transport):

```php
// Sync
$texts->translate(async: false);

// Async (dispatches AppTranslationsMessage)
$texts->translate(async: true);
```

## AppTranslatableService (DB-Backed `__()`)

Overrides core `TranslatableService::translateKey()` to read from DB instead of config arrays. Fallback chain:
1. Exact: language + country + writingStyle
2. Without country
3. Alternate writing style
4. Default language fallback
5. First available value
6. Return key itself

All steps APC-cached (1h TTL).

## Argus Endpoints

| Repo | Endpoint | Cache | Purpose |
|------|----------|-------|---------|
| `ArgusTranslation` | `POST:/rc-translations/deepl/translate` | 1 day | Single text DeepL |
| `ArgusTranslations` | `POST:/ai/openRouter/chatCompletions` | none | Batch LLM translation |
| `ArgusTextEmbedding` | `POST:/ai/openRouter/embeddings` | 1 day | Text vectorization |
| `ArgusDetectedLanguage` | `POST:/ai/openRouter/chatCompletions` | none | Language detection |
