<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\DB\AppTranslations;

use DDD\Domain\Base\Repo\DB\Doctrine\DoctrineModel;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\PersistentCollection;
use DateTime;
use DDD\Domain\Common\Repo\DB\Languages\DBLanguageModel;
use DDD\Domain\Common\Repo\DB\PoliticalEntities\Countries\DBCountryModel;
use DDD\Domain\Base\Repo\DB\Database\DatabaseColumn;

#[ORM\Entity]
#[ORM\ChangeTrackingPolicy('DEFERRED_EXPLICIT')]
#[ORM\Table(name: 'AppTranslationValues')]
class DBAppTranslationValueModel extends DoctrineModel
{
	public const MODEL_ALIAS = 'AppTranslationValue';

	public const TABLE_NAME = 'AppTranslationValues';

	public const ENTITY_CLASS = 'App\Domain\Common\Entities\AppTranslations\AppTranslationValue';

	public static array $virtualColumns = ['virtualCountryId' => [ 'createIndex' => false, 'stored' => true, 'as' => '(IFNULL(countryId, 0))', 'referenceColumn' => 'countryId', 'referenceColumnStored' => true, ]];

	#[ORM\Column(type: 'integer')]
	public ?int $appTranslationKeyId;

	#[ORM\Column(type: 'integer')]
	public ?int $languageId;

	#[ORM\Column(type: 'string')]
	public ?string $translation;

	#[ORM\Column(type: 'integer')]
	public ?int $countryId;

	#[ORM\Column(type: 'string')]
	public ?string $context = 'ONE';

	#[ORM\Column(type: 'string')]
	public ?string $writingStyle = 'INFORMAL';

	#[ORM\Id]
	#[ORM\GeneratedValue]
	#[ORM\Column(type: 'integer')]
	public int $id;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $created;

	#[ORM\Column(type: 'datetime')]
	public ?\DateTime $updated;

	#[ORM\Column(type: 'integer')]
	public int $virtualCountryId;

	#[ORM\ManyToOne(targetEntity: DBAppTranslationKeyModel::class)]
	#[ORM\JoinColumn(name: 'appTranslationKeyId', referencedColumnName: 'id')]
	public ?DBAppTranslationKeyModel $appTranslationKey;

	#[ORM\ManyToOne(targetEntity: DBLanguageModel::class)]
	#[ORM\JoinColumn(name: 'languageId', referencedColumnName: 'id')]
	public ?DBLanguageModel $language;

	#[ORM\ManyToOne(targetEntity: DBCountryModel::class)]
	#[ORM\JoinColumn(name: 'countryId', referencedColumnName: 'id')]
	public ?DBCountryModel $country;

}