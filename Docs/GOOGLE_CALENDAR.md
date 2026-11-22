# Google Agenda et automatisation

Les actions `google_calendar::*` permettent à un scénario de lire et de gérer
des événements Google Agenda avec le même compte de service que Google Sheets.

## Préparation Google

1. Activer l’API Google Calendar sur le projet du compte de service.
2. Partager l’agenda ciblé avec l’adresse `client_email` du compte de service,
   avec le droit de modifier les événements.
3. Utiliser le même JSON de compte de service que pour Google Sheets, dans
   **Automatisation → Configuration → Google API (Sheets et Agenda)**, pour le
   Space concerné.

Un agenda partagé est identifié par `calendar_id` : `primary`, une adresse
e-mail ou l’identifiant de l’agenda. Pour un agenda secondaire, utiliser
l’identifiant visible dans les paramètres de l’agenda, souvent terminé par
`@group.calendar.google.com`.

Le compte de service doit avoir uniquement accès aux agendas nécessaires. Le
JSON est chiffré au repos et affiché uniquement aux administrateurs autorisés.

## Actions

| Action | Rôle |
| --- | --- |
| `google_calendar::listEvents` | Lister les événements d’une période. |
| `google_calendar::getEvent` | Lire un événement par son identifiant. |
| `google_calendar::createEvent` | Créer un événement. |
| `google_calendar::updateEvent` | Modifier les champs fournis d’un événement. |
| `google_calendar::deleteEvent` | Supprimer un événement. Action destructive. |

### Exemples

```php
$evenements = google_calendar::listEvents(
    "primary",
    time_min: "2026-11-19T00:00:00+01:00",
    time_max: "2026-11-26T00:00:00+01:00",
    query: "HelloAsso",
    max_results: 20,
    time_zone: "Europe/Paris"
);
```

```php
$evenement = google_calendar::createEvent(
    "primary",
    "Réunion HelloAsso",
    "2026-11-20T10:00:00+01:00",
    "2026-11-20T11:00:00+01:00",
    description: "Point hebdomadaire",
    location: "Visioconférence",
    time_zone: "Europe/Paris",
    attendees: ["camille@example.test"],
    send_updates: "all"
);
```

```php
google_calendar::updateEvent(
    "primary",
    $evenement->id,
    summary: "Réunion reportée",
    start: "2026-11-20T14:00:00+01:00",
    end: "2026-11-20T15:00:00+01:00"
);
```

```php
google_calendar::deleteEvent("primary", $evenement->id, send_updates: "none");
```

## Dates et journées entières

- Date seule `2026-11-20` : journée entière.
- Date ISO 8601 `2026-11-20T10:00:00+01:00` : événement horaire.
- Pour une journée entière, la date de fin est **exclusive** : un événement du
  20 novembre se termine le 21 novembre.
- `time_zone` est facultatif ; il est utile lorsqu’une date horaire n’a pas de
  décalage explicite.

## Participants et notifications

`attendees` accepte une liste d’adresses e-mail. `send_updates` vaut `none` par
défaut ; `all` et `externalOnly` sont disponibles. Selon la configuration du
compte de service et de l’agenda, Google peut refuser ou ne pas envoyer les
invitations. Tester avec un agenda de démonstration avant la production.

## Sécurité et limites

- Le compte de service doit être limité aux agendas nécessaires.
- Les actions d’écriture sont `EXTERNAL` et exigent `plg_automation__PUBLISH`
  à la publication du scénario.
- Les suppressions sont destructives ; vérifier l’identifiant de l’événement.
- Les appels HTTP ont un timeout court. Les erreurs Google sont journalisées
  sous `GOOGLE_API_ERROR` avec le statut HTTP, une raison bornée, un code
  interne et le nom de l’action ; le message brut du fournisseur n’est pas
  copié et la clé n’est jamais exposée. Le scénario reçoit un diagnostic
  contrôlé.
- Gmail et Google Drive ne sont pas couverts par ce lot.
