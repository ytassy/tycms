<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;

final class GoogleCalendarUpdateEventAction extends GoogleCalendarAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            $calendarId = $this->calendarId($params, $context);
            $eventId = $this->eventId($params, $context);
            $event = $this->event($params, $context, false);
            $sendUpdates = $this->client()->sendUpdates($params['send_updates'] ?? null);
            $updated = $this->client()->service($context->spaceId)->events->patch(
                $calendarId,
                $eventId,
                $event,
                ['sendUpdates' => $sendUpdates],
            );

            return $this->client()->eventSummary($updated);
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
