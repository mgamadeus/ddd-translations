<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Entities\AppTranslations;

use DDD\Domain\Common\Repo\DB\AppTranslations\DBAppTranslationDefaultTerms;
use DDD\Domain\Common\Services\AppTranslations\AppTranslationDefaultTermsService;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Base\Entities\QueryOptions\QueryOptionsTrait;

/**
 * @property AppTranslationDefaultTerm[] $elements;
 * @method AppTranslationDefaultTerm|null first()
 * @method AppTranslationDefaultTerm|null getByUniqueKey(string $uniqueKey)
 * @method AppTranslationDefaultTerm[] getElements()
 * @method static AppTranslationDefaultTermsService getService()
 */
#[LazyLoadRepo(LazyLoadRepo::DB, DBAppTranslationDefaultTerms::class)]
class AppTranslationDefaultTerms extends EntitySet
{
    use QueryOptionsTrait;

    public const string SERVICE_NAME = AppTranslationDefaultTermsService::class;

    /**
     * @return string Returns default terms as pairs of <term>;<defaultTerm>
     */
    public function getDefaultTermsAsStringPairs(): string
    {
        $defaultTermsStringPairs = '';
        foreach ($this->getElements() as $defaultTerm) {
            if ($defaultTerm->defaultTerm ?? null) {
                $defaultTermsStringPairs .= $defaultTerm->term . ';' . $defaultTerm->defaultTerm->term . "\n";
            }
        }
        return $defaultTermsStringPairs;
    }

    /**
     * @return string Returns rows in format <possible term 1>, <possible term 2> ... => <default term>
     */
    public function getDefaultTermsAsAssociationPairs(): string
    {
        $defaultTerms = [];
        foreach ($this->getElements() as $defaultTerm) {
            if ($defaultTerm->defaultTerm ?? null) {
                if (!($defaultTerms[$defaultTerm->defaultTerm->term] ?? null)) {
                    $defaultTerms[$defaultTerm->defaultTerm->term] = [];
                }
                $defaultTerms[$defaultTerm->defaultTerm->term][] = trim($defaultTerm->term);
            }
        }
        $defaultTermsString = '';
        foreach ($defaultTerms as $defaultTerm => $possibleTerms) {
            $defaultTermsString .= implode(', ', $possibleTerms) . ' => ' . $defaultTerm . "\n";
        }
        return $defaultTermsString;
    }
}
