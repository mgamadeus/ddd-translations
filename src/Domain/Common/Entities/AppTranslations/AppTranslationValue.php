<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations;

use DDD\Domain\Common\Entities\Languages\Language;
use DDD\Domain\Common\Entities\PoliticalEntities\Countries\Country;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationValue;
use DDD\Domain\Common\Services\AppTranslations\AppTranslationValuesService;
use DDD\Domain\Base\Entities\Attributes\RolesRequiredForUpdate;
use DDD\Domain\Base\Entities\ChangeHistory\ChangeHistoryTrait;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;
use DDD\Domain\Base\Repo\DB\Database\DatabaseIndex;
use DDD\Domain\Base\Repo\DB\Database\DatabaseVirtualColumn;
use DDD\Infrastructure\Validation\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Length;

/**
 * @method static AppTranslationValuesService getService()
 * @method static DBAppTranslationValue getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAppTranslationValue::class)]
#[RolesRequiredForUpdate(Role::ADMIN)]
#[DatabaseIndex(DatabaseIndex::TYPE_UNIQUE, ['appTranslationKeyId', 'languageId', 'virtualCountryId'])]
class AppTranslationValue extends Entity
{
    use ChangeHistoryTrait, QueryOptionsTrait;

    /** @var int|null The id of the associated AppTranslationKey */
    public ?int $appTranslationKeyId;

    /** @var AppTranslationKey|null The associated AppTranslationKey */
    #[LazyLoad(addAsParent: true)]
    public ?AppTranslationKey $appTranslationKey;

    /** @var int|null The language ID of the translation */
    public ?int $languageId = null;

    /** @var Language|null The language of the translation */
    #[LazyLoad(addAsParent: true)]
    public ?Language $language;

    /** @var string The translated content */
    #[Length(max: 2048)]
    public string $translation;

    /** @var int|null Individual Country id for translation */
    #[DatabaseVirtualColumn(as: '(IFNULL(countryId, 0))')]
    public ?int $countryId = null;

    /** @var Country|null Individual Country for translation */
    #[LazyLoad(addAsParent: true)]
    public ?Country $country;

    /** @var string The translation context, one or many for singular and plural differentiation (e.g. for Project, Projects depending on number) */
    #[Choice([Text::CONTEXT_ONE, Text::CONTEXT_MANY])]
    public string $context = Text::CONTEXT_ONE;

    /** @var string The writing style of the translation */
    #[Choice([Text::WRITING_STYLE_FORMAL, Text::WRITING_STYLE_INFORMAL])]
    public string $writingStyle = Text::WRITING_STYLE_INFORMAL;

    public function uniqueKey(): string
    {
        $key = $this->id ?? null;
        if (!$key) {
            $key = ($this->appTranslationKeyId ?? '');
            $key .= '_' . ($this->languageId ?? '');
            $key .= '_' . ($this->countryId ?? '0');
        }
        return self::uniqueKeyStatic($key);
    }
}
