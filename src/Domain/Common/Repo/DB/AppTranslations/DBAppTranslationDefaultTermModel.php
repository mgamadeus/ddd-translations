<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\AppTranslations;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Common\Repo\DB\Languages\DBLanguageModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AppTranslationDefaultTerms')]
class DBAppTranslationDefaultTermModel extends DoctrineModel
{
	public const MODEL_ALIAS = 'AppTranslationDefaultTerm';

	public const TABLE_NAME = 'AppTranslationDefaultTerms';

	public const ENTITY_CLASS = 'App\Domain\Common\Entities\AppTranslations\AppTranslationDefaultTerm';

	#[ORM\Column(type: 'integer')]
	public ?int $languageId;

	#[ORM\Column(type: 'boolean')]
	public ?bool $isDefaultTerm = false;

	#[ORM\Column(type: 'string')]
	public ?string $term;

	#[ORM\Column(type: 'integer')]
	public ?int $defaultTermId;

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\ManyToOne(targetEntity: DBLanguageModel::class)]
	#[ORM\JoinColumn(name: 'languageId', referencedColumnName: 'id')]
	public ?DBLanguageModel $language;

	#[ORM\ManyToOne(targetEntity: DBAppTranslationDefaultTermModel::class)]
	#[ORM\JoinColumn(name: 'defaultTermId', referencedColumnName: 'id')]
	public ?DBAppTranslationDefaultTermModel $defaultTerm;

}