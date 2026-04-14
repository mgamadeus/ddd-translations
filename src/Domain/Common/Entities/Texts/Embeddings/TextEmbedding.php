<?php

declare (strict_types=1);

namespace DDD\Domain\Common\Entities\Texts\Embeddings;

use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Common\Repo\Argus\Texts\Embeddings\ArgusTextEmbedding;
use DDD\Domain\Base\Entities\LazyLoad\LazyLoadRepo;
use DDD\Domain\Common\Entities\MathEntities\Vector;

/**
 * @property Text $parent
 * @method Text getParent()
 */
#[LazyLoadRepo(LazyLoadRepo::ARGUS, ArgusTextEmbedding::class)]
class TextEmbedding extends Vector
{
    public function uniqueKey(): string
    {
        $key = '';
        if ($parent = $this->getParent()){
            $key .= $parent->uniqueKey();
        }
        return self::uniqueKeyStatic($key);
    }

}