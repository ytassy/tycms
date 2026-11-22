<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;

final class GoogleCalendarListEventsAction extends GoogleCalendarAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            $calendarId = $this->calendarId($params, $context);
            $options = [
                'singleEvents' => true,
                'orderBy' => 'startTime',
                'maxResults' => $this->optionalInt($params['max_results'] ?? null, $context, 50, 1, 250),
            ];

            foreach (['time_min' => 'timeMin', 'time_max' => 'timeMax', 'query' => 'q'] as $input => $option) {
                $value = $params[$input] ?? null;

                if ($value === null || $value === '') {
                    continue;
                }

                $options[$option] = ValueTools::assertString($value, $context->span);
            }

            $timezone = $this->client()->timezone($params['time_zone'] ?? null);

            if ($timezone !== null) {
                $options['timeZone'] = $timezone;
            }

            $events = $this->client()->service($context->spaceId)->events->listEvents($calendarId, $options);

            return array_map(
                fn ($event): array => $this->client()->eventSummary($event),
                $events->getItems() ?? [],
            );
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
