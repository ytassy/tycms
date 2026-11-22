<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;

final class GoogleCalendarDeleteEventAction extends GoogleCalendarAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            $calendarId = $this->calendarId($params, $context);
            $eventId = $this->eventId($params, $context);
            $sendUpdates = $this->client()->sendUpdates($params['send_updates'] ?? null);
            $this->client()->service($context->spaceId)->events->delete(
                $calendarId,
                $eventId,
                ['sendUpdates' => $sendUpdates],
            );

            return true;
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
