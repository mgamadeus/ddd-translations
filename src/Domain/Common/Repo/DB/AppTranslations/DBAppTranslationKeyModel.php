<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\AppTranslations;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AppTranslationKeys')]
class DBAppTranslationKeyModel extends DoctrineModel
{
	public const string MODEL_ALIAS = 'AppTranslationKey';

	public const string TABLE_NAME = 'AppTranslationKeys';

	public const string ENTITY_CLASS = 'App\Domain\Common\Entities\AppTranslations\AppTranslationKey';

	#[ORM\Column(type: 'string')]
	public ?string $key;

	#[ORM\Column(type: 'string')]
	public ?string $translationTemplate;

	#[ORM\Column(type: 'string')]
	public ?string $translationHint;

	#[ORM\Column(type: 'boolean')]
	public ?bool $requiresContext = false;

	#[ORM\Column(type: 'boolean')]
	public ?bool $doNotTranslateAutomatically = false;

	#[ORM\Column(type: 'boolean')]
	public ?bool $reTranslate = false;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\OneToMany(targetEntity: DBAppTranslationValueModel::class, mappedBy: 'appTranslationKey')]
	public PersistentCollection $appTranslationValues;

}