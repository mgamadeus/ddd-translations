<?php

declare(strict_types=1);

namespace DDD\Domain\Common\Services\Texts\Embeddings;

use DDD\Domain\Common\Entities\MathEntities\Vector;
use DDD\Domain\Common\Entities\Texts\Embeddings\TextEmbedding;
use DDD\Domain\Common\Entities\Texts\Text;
use DDD\Domain\Common\Repo\Argus\Texts\Embeddings\ArgusTextEmbedding;
use DDD\Infrastructure\Services\Service;

class AITextEmbeddingsService extends Service
{
    /**
     * Generates a vector embedding for the given text content.
     *
     * @param string $content The text to embed
     * @param int $dimensions Vector dimensions (use Vector::DIMENSION_OPENAI_EMBEDDING_SMALL or _LARGE)
     * @return TextEmbedding
     */
    public function generateEmbeddingForText(string $content, int $dimensions = Vector::DIMENSION_OPENAI_EMBEDDING_SMALL): TextEmbedding
    {
        $previousDimensions = ArgusTextEmbedding::$dimensions;
        ArgusTextEmbedding::$dimensions = $dimensions;
        try {
            $text = new Text($content);
            return $text->embedding;
        } finally {
            ArgusTextEmbedding::$dimensions = $previousDimensions;
        }
    }
}
