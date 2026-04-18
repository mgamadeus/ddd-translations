<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Repo\Argus\Texts\Embeddings;

use DDD\Domain\Base\Repo\Argus\Attributes\ArgusLoad;
use DDD\Domain\Base\Repo\Argus\Traits\ArgusTrait;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusApiOperation;
use DDD\Domain\Base\Repo\Argus\Utils\ArgusCache;
use DDD\Domain\Common\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Common\Repo\Argus\Texts\ArgusText;
use DDD\Domain\Base\Entities\Entity;
use DDD\Domain\Base\Entities\EntitySet;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoad;
use DDD\Domain\Base\Entities\ValueObject;
use DDD\Infrastructure\Exceptions\InternalErrorException;
use ReflectionException;

/**
 * @method ArgusText getParent()
 * @property ArgusText $parent
 * @method TextEmbedding toEntity(array $callPath = [], EntitySet|Entity|ValueObject &$entityInstance = null)
 */
#[ArgusLoad(loadEndpoint: 'POST:/ai/openRouter/embeddings', cacheLevel: ArgusCache::CACHELEVEL_MEMORY_AND_DB, cacheTtl: ArgusCache::CACHE_TTL_ONE_DAY)]
class ArgusTextEmbedding extends TextEmbedding
{
    use ArgusTrait;

    public const string MODEL_SMALL = 'text-embedding-3-small';
    public const string MODEL_LARGE = 'text-embedding-3-large';

    /** @var string The embedding model to use for the next generation call */
    public static string $model = self::MODEL_SMALL;

    /**
     * @param Text $text
     * @param LazyLoad $lazyloadAttributeInstance
     * @return TextEmbedding|null
     * @throws InternalErrorException
     * @throws ReflectionException
     */
    public function lazyload(
        Text &$text,
        LazyLoad &$lazyloadAttributeInstance
    ): TextEmbedding|null {
        $this->setParent($text);
        $this->argusLoad(
            useArgusEntityCache: $lazyloadAttributeInstance->useCache,
            useApiACallCache: $lazyloadAttributeInstance->useCache
        );
        return $this->toEntity();
    }

    public function handleLoadResponse(mixed &$callResponseData = null, ?ArgusApiOperation &$apiOperation = null): void
    {
        if (isset($callResponseData->status) && ($callResponseData->status == 200 || $callResponseData->status == 'OK') && isset($callResponseData->data->data[0])) {
            $responseObject = $callResponseData->data->data[0];
            if (isset($responseObject->embedding) && is_array($responseObject->embedding)) {
                $this->vectorValues = $responseObject->embedding;
            }
        }
        $this->postProcessLoadResponse($callResponseData);
    }

    /**
     * @return array|null
     */
    protected function getLoadPayload(): ?array
    {
        return [
            'body' => [
                'model' => static::$model,
                'input' => $this->getParent()->content,
            ]
        ];
    }
}
