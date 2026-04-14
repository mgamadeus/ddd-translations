<?php

declare (strict_types=1);

namespace DDD\Domain\Common\MessageHandlers;

use DDD\Domain\Base\Entities\MessageHandlers\AppMessageHandler;
use DDD\Domain\Common\Services\AppTranslations\AppTranslationsService;
use DDD\Infrastructure\Services\DDDService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Throwable;

#[AsMessageHandler(fromTransport: 'app_translations')]
class AppTranslationsHandler extends AppMessageHandler
{
    public function __invoke(AppTranslationsMessage $appMessage)
    {
        $this->setAuthAccountFromMessage($appMessage);
        if ($appMessage->processOnWorkspaceIfNecessary()) {
            return;
        }
        /** @var AppTranslationsService $appTranslationsService */
        $appTranslationsService = DDDService::instance()->getService(AppTranslationsService::class);
        try {
            $this->getLogger()->info(
                "Performing AppTranslations for {$appMessage->texts->count()} texts."
            );
            $appTranslationsService->translateAppTranslationsTexts($appMessage->texts, async: false);
            //$this->getLogger()->notice("Regenerating the name for ServiceArea {$serviceAreaRegenerateNameMessage->serviceArea->id} finished.");
        } catch (Throwable $t) {
            $this->logShortException(
                $this->getLogger(),
                'FAIL: Performing AppTranslations',
                $t
            );
        }
    }
}
