<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventAttendee;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Core\Automation\Support\GoogleCalendarClient;
use YTassy\Tycms\Response\ExceptionResponse;

/** Base commune des actions Google Agenda. */
abstract class GoogleCalendarAction extends GoogleApiAction
{
    protected function client(): GoogleCalendarClient
    {
        /** @var GoogleCalendarClient $client */
        $client = $this->getSharedExtensionsClass(GoogleCalendarClient::class);

        return $client;
    }

    /** @param array<string, mixed> $params */
    protected function calendarId(array $params, ActionContext $context): string
    {
        return $this->client()->calendarId($params['calendar_id'] ?? null);
    }

    /** @param array<string, mixed> $params */
    protected function eventId(array $params, ActionContext $context): string
    {
        $eventId = ValueTools::assertString($params['event_id'] ?? null, $context->span);

        if (trim($eventId) === '' || strlen($eventId) > 1024) {
            ExceptionResponse::throwWarning('Identifiant d’événement Google invalide.');
        }

        return $eventId;
    }

    protected function optionalString(mixed $value, ActionContext $context): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return ValueTools::assertString($value, $context->span);
    }

    protected function optionalInt(
        mixed $value,
        ActionContext $context,
        int $default,
        int $minimum,
        int $maximum,
    ): int {
        if ($value === null || $value === '') {
            return $default;
        }

        $integer = ValueTools::assertInt($value, $context->span);

        if ($integer < $minimum || $integer > $maximum) {
            ExceptionResponse::throwWarning('Valeur numérique Google Agenda hors limites.');
        }

        return $integer;
    }

    /** @param array<string, mixed> $params */
    protected function event(array $params, ActionContext $context, bool $requireCore): Event
    {
        $data = [];

        foreach (['summary', 'description', 'location'] as $field) {
            $value = $params[$field] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if (!is_string($value)) {
                throw ValueTools::typeError('string', $value, $context->span);
            }

            $data[$field] = $value;
        }

        $start = $params['start'] ?? null;
        $end = $params['end'] ?? null;

        if ($requireCore && ($start === null || $end === null)) {
            ExceptionResponse::throwWarning('Les dates de début et de fin sont obligatoires.');
        }

        $timezone = $this->client()->timezone($params['time_zone'] ?? null);

        if ($start !== null) {
            $data['start'] = $this->client()->eventDateTime($start, $timezone);
        }

        if ($end !== null) {
            $data['end'] = $this->client()->eventDateTime($end, $timezone);
        }

        $attendees = $params['attendees'] ?? null;

        if ($attendees !== null) {
            if (!is_array($attendees)) {
                throw ValueTools::typeError('array', $attendees, $context->span);
            }

            $data['attendees'] = [];

            foreach ($attendees as $email) {
                if (!is_string($email) || trim($email) === '') {
                    throw ValueTools::typeError('string', $email, $context->span);
                }

                $data['attendees'][] = new EventAttendee(['email' => trim($email)]);
            }
        }

        return new Event($data);
    }
}
