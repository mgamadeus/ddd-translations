<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations;

use DDD\Domain\Common\Entities\Languages\Language;
use DDD\Domain\Common\Entities\Roles\Role;
use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationDefaultTerm;
use DDD\Domain\Common\Services\AppTranslations\AppTranslationDefaultTermsService;
use DDD\Domain\Base\Entities\Attributes\RolesRequiredForUpdate;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * @method static AppTranslationDefaultTermsService getService()
 * @method static DBAppTranslationDefaultTerm getRepoClassInstance(string $repoType = null)
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAppTranslationDefaultTerm::class)]
#[RolesRequiredForUpdate(Role::ADMIN)]
class AppTranslationDefaultTerm extends Entity
{
    use QueryOptionsTrait;

    /** @var int|null The language ID of the default term */
    public ?int $languageId = null;

    /** @var Language|null The language of the default term */
    #[LazyLoad(addAsParent: true)]
    public ?Language $language;

    /** @var bool If true, this is a default term, if not, the terms needs a default term associated */
    public bool $isDefaultTerm = false;

    /** @var string The term used */
    public string $term;

    /** @var AppTranslationDefaultTerm|null The default translation term associated with current word */
    public ?AppTranslationDefaultTerm $defaultTerm;

    /** @var int|null The id of the default translation term associated with current word */
    public ?int $defaultTermId;

    public function uniqueKey(): string
    {
        $key = $this->id ?? null;
        if (!$key) {
            $key = ($this->isDefaultTerm ?? '') . '_' . $this->term . '_' . ($this->languageId ?? '');
        }
        return self::uniqueKeyStatic($key);
    }
}
