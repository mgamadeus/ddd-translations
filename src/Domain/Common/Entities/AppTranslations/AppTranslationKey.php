<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations;

use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationKey;
use DDD\Domain\Common\Services\AppTranslations\AppTranslationKeysService;
use DDD\Domain\Base\Entities\Attributes\RolesRequiredForUpdate;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use Symfony\Component\Validator\Constraints\Length;

/**
 * @method static AppTranslationKeysService getService()
 * @method static DBAppTranslationKey getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAppTranslationKey::class)]
#[RolesRequiredForUpdate(Role::ADMIN)]
class AppTranslationKey extends Entity
{
    use QueryOptionsTrait;

    /** @var string Translation key which serves as basis for translations */
    #[Length(max: 2048)]
    public string $key;

    /** @var string|null
     * The base or template text that serves as the source for translations.
     * This text is used as a placeholder or guide for translating the associated key into different languages.
     * This property is particularly useful for keys that do not contain all the necessary information for translation.
     * By providing a template text, we can ensure that the translations are consistent and accurate across all languages.
     * It is only required on special keys that do not contain the content required to perform a translation
     */
    #[Length(max: 2048)]
    public ?string $translationTemplate;

    /**
     * @var string|null
     * Provides information about the context of the translation and is taken into account on autotranslations.
     * It is especially usefull if the key could be ambiguous and misunderstood without the hint
     */
    #[Length(max: 2048)]
    public ?string $translationHint;

    /** @var bool If true, the main subject of the translation key requires a context, means one and many */
    public bool $requiresContext = false;

    /** @var bool If true, this key shall not be translated automatically */
    public bool $doNotTranslateAutomatically = false;

    /** @var bool If true, this key should be re-translated */
    public bool $reTranslate = false;

    /** @var AppTranslationValues AppTranslationValues for key */
    #[LazyLoad]
    public AppTranslationValues $appTranslationValues;

    public function uniqueKey(): string
    {
        $key = $this->id ?? null;
        if (!$key) {
            $key = $this->key;
        }
        return self::uniqueKeyStatic($key);
    }

    /**
     * @return string If translationTemplate is set, returns this, otherwise key
     */
    public function getContentToTranslate(): string
    {
        return ($this?->translationTemplate ?? null) ? $this?->translationTemplate : $this->key;
    }

    /**
     * Adds AppTranslationValue if it is more specific than existing one, based on given preferences.
     * In case of a more specific one, the current one is removed.
     * AppTranslationValues with different context (singular, plural), though, can exist both in parallel
     * @param AppTranslationValue $appTranslationValue
     * @param string|null $preferredWritingStyle
     * @param string|null $preferredCountryShortCode
     * @return void
     */
    public function addAppTranslationValueWithPreferences(
        AppTranslationValue &$appTranslationValue,
        ?string $preferredWritingStyle = null,
        ?string $preferredCountryShortCode = null,
    ): void {
        if (!isset($this->appTranslationValues)) {
            // if we dont have any other AppTranslationValues set already, we don't have to search and prioritize
            $this->appTranslationValues = new AppTranslationValues();
            $this->appTranslationValues->add($appTranslationValue);
            return;
        }
        foreach ($this->appTranslationValues->getElements() as $existingAppTranslationValue) {
            // new AppTranslationValue has correct writing style, current one, has not
            if ($preferredWritingStyle && ($appTranslationValue->writingStyle == $preferredWritingStyle) && $existingAppTranslationValue->writingStyle != $preferredWritingStyle) {
                $this->appTranslationValues->remove($existingAppTranslationValue);
                $this->appTranslationValues->add($appTranslationValue);
                return;
            }
            // new AppTranslationValue has correct country, current one, has not
            if ($preferredCountryShortCode && ($appTranslationValue?->country?->shortCode ?? null) == $preferredCountryShortCode && ($existingAppTranslationValue->country?->shortCode ?? null) != $preferredCountryShortCode) {
                $this->appTranslationValues->remove($existingAppTranslationValue);
                $this->appTranslationValues->add($appTranslationValue);
                return;
            }
            // if context is different, we add also the new AppTranslationValue
            $this->appTranslationValues->add($appTranslationValue);
            // otherwise we do nothing
        }
    }
}
