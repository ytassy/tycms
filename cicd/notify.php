<?php

declare(strict_types=1);

namespace Tycms\CiCd;

use RuntimeException;
use Throwable;

// Script d'intégration continue : n'est jamais utilisable depuis une requête HTTP.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

/**
 * Notifier la fin d'un workflow GitHub.
 *
 * Politique de bruit, décidée avec Yann :
 * - branche `dev_*` : aucun envoi, GitHub prévient déjà par e-mail ;
 * - branche `prod_*` : échec et succès (une release est alors déposée en attente) ;
 * - tout autre déclencheur (tickets, sécurité) : échec et succès.
 *
 * Le script ne fait jamais échouer le workflow : un canal absent ou en panne
 * produit une sortie diagnostique et un code 0. Une configuration invalide est
 * signalée sans recopier l'URL ni le jeton.
 */

require_once __DIR__ . '/Notifier.php';

const NOTIFY_EVENTS = ['push', 'workflow_dispatch', 'schedule', 'issues', 'issue_comment', 'workflow_run'];

try {
    if ($argc > 1) {
        throw new RuntimeException('Aucun argument attendu ; le contexte vient de l’environnement GitHub.');
    }

    // Contexte fourni par GitHub Actions ; absent hors CI, le script se met en veille.
    $event = getenv('GITHUB_EVENT_NAME') ?: '';
    $ref = getenv('GITHUB_REF_NAME') ?: '';
    $repository = getenv('GITHUB_REPOSITORY') ?: '';
    $runUrl = getenv('NOTIFY_RUN_URL') ?: '';

    // Un push sur une branche de développement ne notifie jamais : GitHub prévient déjà par e-mail.
    // Les autres événements (ticket, sécurité) notifient quelle que soit la branche.
    if ($event === 'push' && str_starts_with($ref, 'dev_')) {
        echo 'Notification ignorée : push sur une branche de développement.', PHP_EOL;
        exit(0);
    }

    // Hors événement prévu, ne rien envoyer : un message inattendu vaut pire que pas de message.
    if ($event !== '' && !in_array($event, NOTIFY_EVENTS, true) && !str_starts_with($ref, 'prod_')) {
        echo 'Notification ignorée : événement sans notification.', PHP_EOL;
        exit(0);
    }

    // Le statut global du job est fourni explicitement par le workflow.
    $status = strtolower(getenv('NOTIFY_STATUS') ?: 'unknown');
    $failed = !in_array($status, ['success', 'succeeded', 'ok'], true);

    $title = ($failed ? 'Échec' : 'Réussite') . ' — ' . $ref . ($repository !== '' ? ' (' . $repository . ')' : '');
    $lines = ['Statut : ' . ($failed ? 'échec' : 'succès')];
    $version = getenv('NOTIFY_VERSION') ?: '';
    if ($version !== '') {
        $lines[] = 'Version : ' . $version;
    }
    $channel = getenv('NOTIFY_CHANNEL') ?: '';
    if ($channel !== '') {
        $lines[] = 'Canal : ' . $channel;
    }
    if ($runUrl !== '') {
        $lines[] = 'Journal : ' . $runUrl;
    }

    $notifier = Notifier::fromEnvironment();
    foreach ($notifier->errors() as $error) {
        fwrite(STDERR, 'Notifications : ' . $error . PHP_EOL);
    }
    if (!$notifier->hasChannels()) {
        echo 'Aucun canal de notification configuré.', PHP_EOL;
        exit(0);
    }

    $notifier->notify($title, implode("\n", $lines));
    echo 'Notification traitée.', PHP_EOL;
} catch (Throwable $error) {
    // Ne jamais faire échouer le workflow à cause d'une notification.
    fwrite(STDERR, 'Notifications : ' . $error->getMessage() . PHP_EOL);
    exit(0);
}
