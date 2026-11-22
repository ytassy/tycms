<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Support;

use Google\Service\Calendar;
use Google\Service\Calendar\Event;
use Google\Service\Calendar\EventDateTime;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Response\ExceptionResponse;

/**
 * Client Google Calendar du compte de service configuré pour un Space.
 *
 * Le JSON du compte de service est le même que pour Google Sheets. L'agenda
 * ciblé doit être partagé avec l'adresse client_email du compte.
 */
final class GoogleCalendarClient extends BaseHelper
{
    private const HTTP_TIMEOUT_SECONDS = 15;

    /** @var array<int, Calendar> */
    private array $services = [];

    public function service(int $spaceId): Calendar
    {
        if (isset($this->services[$spaceId])) {
            return $this->services[$spaceId];
        }

        /** @var GoogleSheetsClient $sheets */
        $sheets = $this->getSharedExtensionsClass(GoogleSheetsClient::class);
        $config = $sheets->config($spaceId);
        $client = new \Google\Client();
        $client->setApplicationName('TyCMS Automation');
        $client->setAuthConfig($config);
        $client->setScopes([Calendar::CALENDAR_EVENTS]);
        $client->setAccessType('offline');
        $client->setHttpClient(new \GuzzleHttp\Client([
            'timeout' => self::HTTP_TIMEOUT_SECONDS,
            'connect_timeout' => 5,
        ]));

        return $this->services[$spaceId] = new Calendar($client);
    }

    public function calendarId(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A[A-Za-z0-9@._-]{1,300}\z/', trim($value)) !== 1) {
            ExceptionResponse::throwWarning('Identifiant d’agenda Google invalide.');
        }

        return trim($value);
    }

    public function eventDateTime(mixed $value, ?string $timezone): EventDateTime
    {
        if (!is_string($value) || trim($value) === '') {
            ExceptionResponse::throwWarning('Date Google Agenda invalide.');
        }

        $value = trim($value);

        if (preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $value) === 1) {
            return new EventDateTime(['date' => $value]);
        }

        $data = ['dateTime' => $value];

        if ($timezone !== null && $timezone !== '') {
            $data['timeZone'] = $timezone;
        }

        return new EventDateTime($data);
    }

    public function timezone(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_string($value) || !in_array($value, timezone_identifiers_list(), true)) {
            ExceptionResponse::throwWarning('Fuseau horaire Google Agenda invalide.');
        }

        return $value;
    }

    public function sendUpdates(mixed $value): string
    {
        if ($value === null || $value === '') {
            return 'none';
        }

        if (!is_string($value) || !in_array($value, ['all', 'externalOnly', 'none'], true)) {
            ExceptionResponse::throwWarning('Option d’envoi Google Agenda invalide.');
        }

        return $value;
    }

    /** @return array<string, mixed> */
    public function eventSummary(Event $event): array
    {
        $start = $event->getStart();
        $end = $event->getEnd();

        return [
            'id' => (string) $event->getId(),
            'summary' => (string) $event->getSummary(),
            'status' => (string) $event->getStatus(),
            'html_link' => $event->getHtmlLink(),
            'location' => $event->getLocation(),
            'start' => $start !== null ? ($start->getDateTime() ?? $start->getDate()) : null,
            'end' => $end !== null ? ($end->getDateTime() ?? $end->getDate()) : null,
        ];
    }
}
