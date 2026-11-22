<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationParameterDefinition;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionEffect;
use YTassy\Tycms\CoreExts\Automation\Permissions as AutomationPermissions;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\GoogleCalendarCreateEventAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\GoogleCalendarDeleteEventAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\GoogleCalendarGetEventAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\GoogleCalendarListEventsAction;
use YTassy\Tycms\CoreExts\Core\Automation\Actions\GoogleCalendarUpdateEventAction;

/** Actions Google Agenda exposées au langage de scénarios. */
final class GoogleCalendarAutomationActions
{
    /** @return list<AutomationActionDefinition> */
    public static function definitions(): array
    {
        return [
            new AutomationActionDefinition(
                id: 'google_calendar::listEvents',
                label: 'Lister des événements Google Agenda',
                handlerClass: GoogleCalendarListEventsAction::class,
                description: 'Liste les événements d’un agenda sur une période.',
                parameters: [
                    ...self::calendarParams(),
                    self::stringParam('time_min', 'Début', 'Date ISO 8601 minimale, par exemple 2026-11-19T00:00:00+01:00.'),
                    self::stringParam('time_max', 'Fin', 'Date ISO 8601 maximale.'),
                    self::stringParam('query', 'Recherche', 'Texte recherché dans les événements.'),
                    new AutomationParameterDefinition(
                        'max_results',
                        'int',
                        false,
                        50,
                        'Limite',
                        'Nombre maximal d’événements, entre 1 et 250.',
                    ),
                    self::timezoneParam(),
                ],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'google_calendar::getEvent',
                label: 'Lire un événement Google Agenda',
                handlerClass: GoogleCalendarGetEventAction::class,
                description: 'Retourne un événement par son identifiant.',
                parameters: [
                    ...self::calendarParams(),
                    ...self::eventParams(),
                ],
                returnType: 'array',
                effect: FunctionEffect::READ,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'google_calendar::createEvent',
                label: 'Créer un événement Google Agenda',
                handlerClass: GoogleCalendarCreateEventAction::class,
                description: 'Crée un événement dans un agenda partagé avec le compte de service.',
                parameters: [
                    ...self::calendarParams(),
                    new AutomationParameterDefinition('summary', 'string', true, label: 'Titre', description: 'Titre de l’événement.'),
                    new AutomationParameterDefinition('start', 'string', true, label: 'Début', description: 'Date ISO 8601, ou AAAA-MM-JJ pour une journée entière.'),
                    new AutomationParameterDefinition('end', 'string', true, label: 'Fin', description: 'Date ISO 8601, ou AAAA-MM-JJ. Pour une journée entière, la fin est exclusive.'),
                    self::stringParam('description', 'Description', 'Description facultative.'),
                    self::stringParam('location', 'Lieu', 'Lieu facultatif.'),
                    self::timezoneParam(),
                    self::attendeesParam(),
                    self::sendUpdatesParam(),
                ],
                returnType: 'array',
                effect: FunctionEffect::EXTERNAL,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'google_calendar::updateEvent',
                label: 'Modifier un événement Google Agenda',
                handlerClass: GoogleCalendarUpdateEventAction::class,
                description: 'Modifie les champs fournis d’un événement existant.',
                parameters: [
                    ...self::calendarParams(),
                    ...self::eventParams(),
                    self::stringParam('summary', 'Titre', 'Nouveau titre.'),
                    self::stringParam('description', 'Description', 'Nouvelle description.'),
                    self::stringParam('location', 'Lieu', 'Nouveau lieu.'),
                    self::stringParam('start', 'Début', 'Nouvelle date ISO 8601 ou AAAA-MM-JJ.'),
                    self::stringParam('end', 'Fin', 'Nouvelle date ISO 8601 ou AAAA-MM-JJ.'),
                    self::timezoneParam(),
                    self::attendeesParam(),
                    self::sendUpdatesParam(),
                ],
                returnType: 'array',
                effect: FunctionEffect::EXTERNAL,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
            new AutomationActionDefinition(
                id: 'google_calendar::deleteEvent',
                label: 'Supprimer un événement Google Agenda',
                handlerClass: GoogleCalendarDeleteEventAction::class,
                description: 'Supprime un événement. Action destructive.',
                parameters: [
                    ...self::calendarParams(),
                    ...self::eventParams(),
                    self::sendUpdatesParam(),
                ],
                returnType: 'bool',
                effect: FunctionEffect::EXTERNAL,
                requiredPermissions: [AutomationPermissions::PUBLISH],
                timeoutSeconds: 15,
            ),
        ];
    }

    /** @return list<AutomationParameterDefinition> */
    private static function calendarParams(): array
    {
        return [
            new AutomationParameterDefinition(
                'calendar_id',
                'string',
                true,
                label: 'Agenda',
                description: 'primary, adresse e-mail ou identifiant de l’agenda partagé avec le compte de service.',
            ),
        ];
    }

    /** @return list<AutomationParameterDefinition> */
    private static function eventParams(): array
    {
        return [
            new AutomationParameterDefinition(
                'event_id',
                'string',
                true,
                label: 'Événement',
                description: 'Identifiant Google de l’événement.',
            ),
        ];
    }

    private static function stringParam(string $name, string $label, string $description): AutomationParameterDefinition
    {
        return new AutomationParameterDefinition($name, 'string', false, null, $label, $description);
    }

    private static function timezoneParam(): AutomationParameterDefinition
    {
        return new AutomationParameterDefinition(
            'time_zone',
            'string',
            false,
            null,
            'Fuseau',
            'Fuseau IANA, par exemple Europe/Paris.',
        );
    }

    private static function attendeesParam(): AutomationParameterDefinition
    {
        return new AutomationParameterDefinition(
            'attendees',
            'array',
            false,
            null,
            'Participants',
            'Liste d’adresses e-mail. Les invitations peuvent dépendre des droits du compte de service.',
        );
    }

    private static function sendUpdatesParam(): AutomationParameterDefinition
    {
        return new AutomationParameterDefinition(
            'send_updates',
            'string',
            false,
            'none',
            'Notifications',
            'none, all ou externalOnly.',
        );
    }

    private function __construct()
    {
    }
}
