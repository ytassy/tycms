<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;

final class GoogleCalendarGetEventAction extends GoogleCalendarAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            $calendarId = $this->calendarId($params, $context);
            $eventId = $this->eventId($params, $context);
            $event = $this->client()->service($context->spaceId)->events->get($calendarId, $eventId);

            return $this->client()->eventSummary($event);
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
