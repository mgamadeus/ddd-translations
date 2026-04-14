<?php

declare(strict_types=1);

namespace DDD\Domain\Common;

/**
 * Prompt name constants for the Translations module.
 * Each constant maps to a prompt markdown file under config/app/AI/Prompts/.
 */
class TranslationPrompts
{
    /** @var string Prompt for Entity translations with Translatable */
    public const string ENTITY_TRANSLATABLE = 'Common.Translations.Entity.Translatable';

    /** @var string Prompt for App UI translations (single locale, informal tone) */
    public const string APP_TRANSLATIONS_SINGLE_LOCALE_INFORMAL = 'Common.Translations.AppTranslations.SingleLocaleInformal';

    /** @var string Prompt for App UI translations (single locale, formal tone) */
    public const string APP_TRANSLATIONS_SINGLE_LOCALE_FORMAL = 'Common.Translations.AppTranslations.SingleLocaleFormal';

    /** @var string Detects the primary language code of a text */
    public const string DETECTED_LANGUAGE = 'Common.Texts.DetectedLanguage';
}
