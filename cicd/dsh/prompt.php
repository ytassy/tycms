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
 * Composer la tâche envoyée à DSH : une invite versionnée du dépôt, puis le
 * texte du ticket encadré et neutralisé.
 *
 * Le ticket est écrit par une personne extérieure : il ne doit jamais pouvoir
 * refermer son bloc et se faire passer pour une consigne. Les occurrences des
 * balises sont donc remplacées avant insertion, et la taille est bornée.
 *
 * Usage : php cicd/dsh/prompt.php <triage|fix> <fichier-ticket> [version] [fichier-commentaires]
 */

const MAX_TICKET_BYTES = 60000;
const MAX_COMMENTS_BYTES = 20000;

const PROMPTS = [
    'triage' => ['file' => 'triage.md', 'label' => 'TICKET'],
    'fix' => ['file' => 'fix.md', 'label' => 'TICKET'],
    'audit' => ['file' => 'audit.md', 'label' => 'CONTEXTE'],
];

try {
    if ($argc < 3 || $argc > 5) {
        throw new RuntimeException('Usage : php cicd/dsh/prompt.php <triage|fix> <fichier-ticket> [version] [fichier-commentaires]');
    }
    $kind = $argv[1];
    if (!isset(PROMPTS[$kind])) {
        throw new RuntimeException('Type d’invite inconnu.');
    }
    $label = PROMPTS[$kind]['label'];
    $ticketFile = $argv[2];
    if (!is_file($ticketFile) || is_link($ticketFile)) {
        throw new RuntimeException('Fichier de ticket absent.');
    }
    $ticket = file_get_contents($ticketFile, false, null, 0, MAX_TICKET_BYTES + 1);
    if (!is_string($ticket)) {
        throw new RuntimeException('Fichier de ticket illisible.');
    }
    if ($ticket === '') {
        $ticket = '(ticket vide)';
    }
    if (strlen($ticket) > MAX_TICKET_BYTES) {
        throw new RuntimeException('Ticket trop volumineux pour une analyse fiable.');
    }

    $promptFile = __DIR__ . '/prompts/' . PROMPTS[$kind]['file'];
    $prompt = is_file($promptFile) ? file_get_contents($promptFile) : false;
    if (!is_string($prompt) || $prompt === '') {
        throw new RuntimeException('Invite absente ou vide.');
    }

    // Neutraliser les balises : le ticket ne peut pas clore son bloc prématurément.
    // Le motif couvre la casse, les espaces et un nombre quelconque de chevrons :
    // « <<FIN_TICKET>> », « <FIN_TICKET> » ou « <<<<FIN_TICKET>>>> » seraient
    // sinon lus comme un délimiteur par un modèle peu attentif.
    // Les chevrons sont encodés en entités : le texte reste lisible, mais aucune
    // balise ne subsiste.
    $pattern = '/<{1,}\s*(FIN_)?' . preg_quote($label, '/') . '\s*>{1,}/i';
    $escape = static fn (array $match): string => str_replace(['<', '>'], ['&lt;', '&gt;'], $match[0]);
    $safe = preg_replace_callback($pattern, $escape, $ticket);
    if (!is_string($safe)) {
        throw new RuntimeException('Neutralisation des balises impossible.');
    }

    // Les commentaires du ticket portent souvent la précision qui manque au corps :
    // les joindre évite de corriger à l'aveugle. Ils sont bornés et neutralisés
    // comme le ticket lui-même, puisqu'ils viennent de la même source non fiable.
    $comments = '';
    if ($label === 'TICKET' && $argc === 5 && is_file($argv[4]) && !is_link($argv[4])) {
        $raw = file_get_contents($argv[4], false, null, 0, MAX_COMMENTS_BYTES + 1);
        if (is_string($raw) && $raw !== '') {
            // Couper sur une frontière de caractère : un octet isolé casserait l'encodage.
            $comments = mb_strcut($raw, 0, MAX_COMMENTS_BYTES, 'UTF-8');
            if (strlen($raw) > MAX_COMMENTS_BYTES) {
                $comments .= "

(commentaires tronqués)";
            }
        }
    }
    if ($comments !== '') {
        $safe .= "\n\n--- Commentaires du ticket ---\n" . $comments;
        $safe = preg_replace_callback($pattern, $escape, $safe) ?? $safe;
    }

    $version = $argc >= 4 ? trim((string) $argv[3]) : '';
    if ($version !== '' && preg_match('/\A[0-9A-Za-z._-]{1,40}\z/', $version) !== 1) {
        $version = '';
    }

    $output = rtrim($prompt) . "\n\n"
        . "## Contenu à analyser\n\n"
        . ($version !== '' ? "Version du projet : " . $version . "\n\n" : '')
        . '<<<' . $label . ">>>\n" . $safe . "\n<<<FIN_" . $label . ">>>\n";

    fwrite(STDOUT, $output);
} catch (Throwable $error) {
    fwrite(STDERR, 'Invite refusée : ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
