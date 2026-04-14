<?php

declare (strict_types=1);

namespace DDD\Domain\Common\MessageHandlers;

use DDD\Domain\Common\Entities\Texts\Texts;
use DDD\Domain\Base\Entities\MessageHandlers\AppMessage;

class AppTranslationsMessage extends AppMessage
{
    /** @var Texts The Texts to be translated */
    public ?Texts $texts;

    public static string $messageHandler = AppTranslationsHandler::class;

    public function __construct(?Texts $texts = null)
    {
        parent::__construct();
        $this->texts = $texts;
    }
}