<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\AppTranslations;

use DDD\Domain\Base\Entities\Translatable\Translatable;
use DDD\Domain\Base\Services\TranslatableService;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationKey;
use DDD\Domain\Common\Entities\AppTranslations\AppTranslationValue;
use DDD\Domain\Common\Entities\Languages\Language;
use DDD\Domain\Common\Entities\PoliticalEntities\Countries\Country;
use DDD\Domain\Common\Services\Languages\LanguagesService;
use DDD\Domain\Common\Services\PoliticalEntities\CountriesService;
use DDD\Infrastructure\Cache\Cache;
use Psr\Cache\InvalidArgumentException;

/**
 * DB-backed TranslatableService override.
 *
 * Translates using AppTranslationKey + AppTranslationValue entities from the database
 * instead of the config-based Common.Translations array used by the DDD core
 * TranslatableService.
 *
 * To activate in your project, register it as the implementation for
 * DDD\Domain\Base\Services\TranslatableService in your services.yaml:
 *
 * ```yaml
 * DDD\Domain\Base\Services\TranslatableService:
 *     class: DDD\Domain\Common\Services\Translations\AppTranslatableService
 *     public: true
 * ```
 *
 * Or extend it in your app's TranslatableService override to combine with additional logic.
 */
class AppTranslatableService extends TranslatableService
{
    protected const int CACHE_TTL = 3600; // 1 hour

    /**
     * Translates using AppTranslationKey + AppTranslationValue entities from the database.
     * Applies the same fallback chain as the parent (config-based) implementation:
     * 1. Exact match: language + country + writingStyle
     * 2. Without country: language + writingStyle
     * 3. Alternate writing style: language + opposite writingStyle
     * 4. Default language fallback
     * 5. Native value fallback (first available translation)
     * 6. Return key itself
     *
     * @throws InvalidArgumentException
     */
    public function translateKey(
        string $translationKey,
        ?string $languageCode = null,
        ?string $countryCode = null,
        ?string $writingStyle = null,
        array $placeholders = []
    ): string {
        $languageCode = $languageCode ?? $this->getCurrentLanguageCode();
        $countryCode = $countryCode ?? ($this->getCurrentCountryCode() ?? '');
        $writingStyle = $writingStyle ?? $this->getCurrentWritingStyle();

        // Resolve the AppTranslationKey
        $appKey = $this->resolveKey($translationKey);
        if (!$appKey || !$appKey->id) {
            return Translatable::replacePlaceholders($translationKey, $placeholders);
        }

        $keyId = (int)$appKey->id;
        $languageId = $this->resolveLanguageId($languageCode);
        $countryId = $countryCode ? $this->resolveCountryId($countryCode) : null;

        /** @var AppTranslationValuesService $valuesService */
        $valuesService = AppTranslationValue::getService();

        // 1. Exact match: language + country + writingStyle
        if ($languageId) {
            $translation = $this->resolveTranslation($keyId, $languageId, $writingStyle, $countryId, $valuesService);
            if ($translation !== null) {
                return Translatable::replacePlaceholders($translation, $placeholders);
            }
        }

        // Default Fallbacks
        if ($languageId) {
            // 2. Without country: language + writingStyle (no country)
            if ($countryId) {
                $translation = $this->resolveTranslation($keyId, $languageId, $writingStyle, null, $valuesService);
                if ($translation !== null) {
                    return Translatable::replacePlaceholders($translation, $placeholders);
                }
            }

            // 3. Alternate writing style: language + opposite writingStyle (no country)
            $altWritingStyle = $writingStyle === Translatable::WRITING_STYLE_INFORMAL
                ? Translatable::WRITING_STYLE_FORMAL
                : Translatable::WRITING_STYLE_INFORMAL;
            $translation = $this->resolveTranslation($keyId, $languageId, $altWritingStyle, null, $valuesService);
            if ($translation !== null) {
                return Translatable::replacePlaceholders($translation, $placeholders);
            }
        }

        // 4. Default language fallback
        if ($this->fallbackToDefaultLanguageIfNoTranslationIsPresent()) {
            $defaultLanguageId = $this->resolveLanguageId($this->getDefaultLanguageCode());
            if ($defaultLanguageId && $defaultLanguageId !== $languageId) {
                $translation = $this->resolveTranslation($keyId, $defaultLanguageId, $writingStyle, null, $valuesService);
                if ($translation !== null) {
                    return Translatable::replacePlaceholders($translation, $placeholders);
                }
            }
        }

        // 5. Native value fallback (first available for this key)
        if ($this->fallbackToNativeValueIfNoTranslationIsPresent()) {
            $cacheKey = "appTranslationValue_first_{$keyId}";
            $cached = Cache::instance()->get($cacheKey);
            if ($cached !== false) {
                // cached hit: null = known miss, string = translation
                if ($cached !== null) {
                    return Translatable::replacePlaceholders($cached, $placeholders);
                }
            } else {
                $value = $valuesService->findFirstValueForKey($keyId);
                if ($value) {
                    Cache::instance()->set($cacheKey, $value->translation, static::CACHE_TTL);
                    return Translatable::replacePlaceholders($value->translation, $placeholders);
                }
                Cache::instance()->set($cacheKey, null, static::CACHE_TTL);
            }
        }

        // 6. Nothing found — return the key itself
        return Translatable::replacePlaceholders($translationKey, $placeholders);
    }

    /**
     * Resolves AppTranslationKey by key string. Uses APC cache.
     */
    protected function resolveKey(string $keyString): ?AppTranslationKey
    {
        $cacheKey = 'appTranslationKey_' . md5($keyString);
        $cached = Cache::instance()->get($cacheKey);
        if ($cached !== false) {
            // cached hit: null = known miss, array = key data
            if ($cached === null) {
                return null;
            }
            $appKey = new AppTranslationKey();
            $appKey->id = $cached['id'];
            $appKey->key = $keyString;
            return $appKey;
        }
        /** @var AppTranslationKeysService $keysService */
        $keysService = AppTranslationKey::getService();
        $appKey = $keysService->findByKeyString($keyString);
        if ($appKey && $appKey->id) {
            Cache::instance()->set($cacheKey, ['id' => (int)$appKey->id], static::CACHE_TTL);
        } else {
            Cache::instance()->set($cacheKey, null, static::CACHE_TTL);
        }
        return $appKey;
    }

    /**
     * Resolves languageCode → languageId. Uses APC cache.
     */
    protected function resolveLanguageId(string $languageCode): ?int
    {
        $cacheKey = 'languageId_' . $languageCode;
        $cached = Cache::instance()->get($cacheKey);
        if ($cached !== false) {
            return $cached; // null = known miss, int = languageId
        }
        /** @var LanguagesService $languagesService */
        $languagesService = Language::getService();
        $language = $languagesService->findByLanguageCode($languageCode);
        $id = $language?->id ? (int)$language->id : null;
        Cache::instance()->set($cacheKey, $id, static::CACHE_TTL);
        return $id;
    }

    /**
     * Resolves countryCode → countryId. Uses APC cache.
     */
    protected function resolveCountryId(string $countryCode): ?int
    {
        if (!$countryCode) {
            return null;
        }
        $cacheKey = 'countryId_' . $countryCode;
        $cached = Cache::instance()->get($cacheKey);
        if ($cached !== false) {
            return $cached; // null = known miss, int = countryId
        }
        /** @var CountriesService $countriesService */
        $countriesService = Country::getService();
        $country = $countriesService->findByShortCode($countryCode);
        $id = $country?->id ? (int)$country->id : null;
        Cache::instance()->set($cacheKey, $id, static::CACHE_TTL);
        return $id;
    }

    /**
     * Resolves a translation value with APC cache.
     * Returns the translation string or null if not found.
     */
    protected function resolveTranslation(
        int $keyId,
        int $languageId,
        string $writingStyle,
        ?int $countryId,
        AppTranslationValuesService $valuesService
    ): ?string {
        $cacheKey = "appTranslationValue_{$keyId}_{$languageId}_{$writingStyle}_" . ($countryId ?? 0);
        $cached = Cache::instance()->get($cacheKey);
        if ($cached !== false) {
            // cached hit: null = known miss, string = translation
            return $cached;
        }
        $value = $valuesService->findValue($keyId, $languageId, $writingStyle, $countryId);
        if ($value) {
            Cache::instance()->set($cacheKey, $value->translation, static::CACHE_TTL);
            return $value->translation;
        }
        // Cache the miss as null to avoid repeated DB lookups
        Cache::instance()->set($cacheKey, null, static::CACHE_TTL);
        return null;
    }
}
