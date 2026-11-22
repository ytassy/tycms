<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;

final class GoogleCalendarCreateEventAction extends GoogleCalendarAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            $calendarId = $this->calendarId($params, $context);
            $event = $this->event($params, $context, true);
            $sendUpdates = $this->client()->sendUpdates($params['send_updates'] ?? null);
            $created = $this->client()->service($context->spaceId)->events->insert(
                $calendarId,
                $event,
                ['sendUpdates' => $sendUpdates],
            );

            return $this->client()->eventSummary($created);
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
