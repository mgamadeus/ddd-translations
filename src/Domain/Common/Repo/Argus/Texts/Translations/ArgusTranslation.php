<?php

declare (strict_types=1);

namespace DDD\Domain\Common\Repo\Argus\Texts\Translations;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Common\Entities\Texts\DetectedLanguage;
use DDD\Domain\Common\Entities\Texts\Translations\Translation;

/**
 * translates text with deepl, https://swagger.rankingcoach.com/?urls.primaryName=rc-translations%20%5Bmicroservice%20prometheus%20swagger%5D#/translation/post_deepl_translate
 * https://www.deepl.com/en/docs-api/
 */
#[ArgusLoad(loadEndpoint: 'POST:/rc-translations/deepl/translate', cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB, cacheTtl: ArgusCache::CACHE_TTL_ONE_DAY)]
class ArgusTranslation extends Translation
{
    use ArgusTrait;

    /**
     * @return array|null
     */
    protected function getLoadPayload(): ?array
    {
        $originalText = $this->getOriginalText();
        return [
            'body' => [
                'text' => $originalText->content,
                'source_lang' => $originalText->locale->languageCode,
                'target_lang' => $this->locale->languageCode
            ]
        ];
    }

    public function handleLoadResponse(mixed &$callResponseData = null, ?ArgusApiOperation &$apiOperation = null): void
    {
        $responseObject = null;
        if (isset($callResponseData->status) && ($callResponseData->status == 200 || $callResponseData->status == 'OK') && ($callResponseData->data[0] ?? null)) {
            $responseObject = $callResponseData->data[0];
        }
        if (!$responseObject) {
            $this->postProcessLoadResponse($callResponseData, false);
            return;
        }
        $this->content = $responseObject->text;
        $this->detectedLanguage = new DetectedLanguage();
        $this->detectedLanguage->languageCode = $responseObject->detected_source_language;
        $this->postProcessLoadResponse($callResponseData);
    }
}