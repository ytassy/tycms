<?php

declare(strict_types=1);

namespace Tycms\Distribution;

/**
 * Pages HTML du mini-UI d'administration du serveur de distribution.
 *
 * Le rendu est volontairement autonome : aucune ressource externe, un nonce CSP
 * pour le script de confirmation et l'échappement de toute donnée reçue. Les
 * notes de version sont affichées ici après authentification, en texte simple,
 * sans interpréter le Markdown, pour éviter tout HTML hostile dans ce domaine
 * qui distribue les mises à jour.
 */
final class AdminView
{
    private const STYLE = <<<CSS
    :root { color-scheme: light dark; font-family: system-ui, sans-serif; }
    body { margin: 0; background: #f4f5f7; color: #1d2129; }
    main { max-width: 56rem; margin: 0 auto; padding: 2rem 1.25rem 4rem; }
    header { display: flex; gap: 1rem; align-items: baseline; justify-content: space-between; flex-wrap: wrap; }
    h1 { font-size: 1.4rem; margin: 0 0 1rem; }
    h2 { font-size: 1.1rem; margin: 2rem 0 .75rem; }
    section { background: #fff; border: 1px solid #dfe3e8; border-radius: 8px; padding: 1rem 1.25rem; margin-bottom: 1rem; }
    .muted { color: #5c6470; font-size: .875rem; }
    table { width: 100%; border-collapse: collapse; font-size: .9rem; }
    th, td { text-align: left; padding: .45rem .5rem; border-bottom: 1px solid #eef0f3; vertical-align: top; }
    pre { white-space: pre-wrap; word-break: break-word; background: #f7f8fa; border-radius: 6px; padding: .75rem; max-height: 18rem; overflow: auto; font-size: .8rem; }
    form { display: inline; }
    button { font: inherit; padding: .4rem .8rem; border-radius: 6px; border: 1px solid #c4cad3; background: #fff; cursor: pointer; }
    button.primary { background: #1a5fb4; border-color: #1a5fb4; color: #fff; }
    button.danger { background: #fff; border-color: #c01c28; color: #c01c28; }
    .actions { display: flex; gap: .5rem; flex-wrap: wrap; margin-top: .75rem; }
    .error { background: #fce8e8; border-color: #e5a3a3; }
    .notice { background: #e8f3e8; border-color: #a9cfa9; }
    input[type=text], input[type=password] { font: inherit; padding: .4rem .5rem; width: 100%; box-sizing: border-box; }
    label { display: block; margin-bottom: .75rem; }
    .badge { display: inline-block; font-size: .75rem; padding: .1rem .45rem; border-radius: 99px; border: 1px solid #c4cad3; }
    .badge.beta { border-color: #c39a1a; color: #8a6b00; }
    .badge.prod { border-color: #2e7d32; color: #2e7d32; }
    CSS;

    private string $nonce;

    public function __construct(private string $title = 'Distribution TyCMS')
    {
        $this->nonce = base64_encode(random_bytes(18));
    }

    public function nonce(): string
    {
        return $this->nonce;
    }

    public function send(string $html, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'nonce-{$this->nonce}'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'");
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        echo $html;
        exit;
    }

    public function login(?string $error = null): string
    {
        $body = $this->header('Connexion')
            . ($error !== null ? '<section class="error" role="alert">' . self::e($error) . '</section>' : '')
            . '<section><form method="post" action="?action=login">'
            . '<label>Compte<input type="text" name="user" autocomplete="username" required maxlength="64"></label>'
            . '<label>Mot de passe<input type="password" name="password" autocomplete="current-password" required></label>'
            . '<button class="primary" type="submit">Se connecter</button>'
            . '</form></section>';

        return $this->page($body);
    }

    /** @param list<array> $pending  @param list<array> $approved */
    public function home(array $pending, array $approved, string $csrf, ?string $notice = null, ?string $error = null): string
    {
        $body = $this->header('Pilotage des versions')
            . ($error !== null ? '<section class="error" role="alert">' . self::e($error) . '</section>' : '')
            . ($notice !== null ? '<section class="notice" role="status">' . self::e($notice) . '</section>' : '');

        // Les propositions en attente d'approbation : seulement elles peuvent être refusées.
        $body .= '<h2>En attente d’approbation (' . count($pending) . ')</h2>';
        if ($pending === []) {
            $body .= '<section class="muted">Aucune version en attente. Une publication arrivée par le build apparaîtra ici.</section>';
        }
        foreach ($pending as $release) {
            $body .= $this->releaseCard($release, $csrf, true);
        }

        $body .= '<h2>Versions approuvées (' . count($approved) . ')</h2>';
        if ($approved === []) {
            $body .= '<section class="muted">Aucune version approuvée.</section>';
        }
        foreach ($approved as $release) {
            $body .= $this->releaseCard($release, $csrf, false);
        }

        $body .= '<script nonce="' . $this->nonce . '">'
            . 'for (const f of document.querySelectorAll("form[data-confirm]")){'
            . 'f.addEventListener("submit",e=>{if(!confirm(f.dataset.confirm)){e.preventDefault();}});}'
            . '</script>';

        return $this->page($body);
    }

    private function releaseCard(array $release, string $csrf, bool $pending): string
    {
        $manifest = $release['manifest'];
        $version = self::e($manifest['version']);
        $channel = $release['channel'] ?? Repository::CHANNEL_PENDING;
        $badge = match ($channel) {
            Repository::CHANNEL_BETA => '<span class="badge beta">Bêta</span>',
            Repository::CHANNEL_PROD => '<span class="badge prod">Prod</span>',
            default => '<span class="badge">En attente</span>',
        };

        $html = '<section><header><h2 style="margin:0">' . $version . ' ' . $badge . '</h2></header>'
            . '<table>'
            . '<tr><th>Identité</th><td>' . self::e($manifest['archive']['filename']) . '</td></tr>'
            . '<tr><th>Taille</th><td>' . number_format((int) $manifest['archive']['bytes'], 0, ',', ' ') . ' octets</td></tr>'
            . '<tr><th>SHA-256</th><td><code>' . self::e($manifest['archive']['sha256']) . '</code></td></tr>'
            . '<tr><th>Compatibilité</th><td>PHP ' . self::e($manifest['php_min']) . ' – ' . self::e($manifest['php_max_exclusive']) . '</td></tr>'
            . '<tr><th>Déposée</th><td>' . self::e(date('Y-m-d H:i:s T', (int) ($release['created_at'] ?? time()))) . '</td></tr>'
            . '</table>';

        // Les notes sont montrées intégralement en texte simple avant toute décision.
        $html .= '<details><summary>Notes de version</summary><pre>'
            . self::e($release['notes'] ?? 'Notes absentes (ancienne release).')
            . '</pre></details>';

        $html .= '<div class="actions">';
        if ($pending) {
            $html .= $this->form($csrf, 'approve', $manifest['version'], Repository::CHANNEL_BETA, 'Approuver Bêta', 'primary',
                'Servir cette version aux installations qui acceptent la bêta ? Le retour en attente sera impossible.');
            $html .= $this->form($csrf, 'approve', $manifest['version'], Repository::CHANNEL_PROD, 'Approuver Prod', 'primary',
                'Servir cette version à toutes les installations ? Le canal sera impossible à modifier ensuite.');
            $html .= $this->form($csrf, 'discard', $manifest['version'], null, 'Refuser', 'danger',
                'Supprimer définitivement cette proposition ? Les fichiers seront effacés et la version pourra être renvoyée.');
        } elseif ($channel === Repository::CHANNEL_BETA) {
            $html .= $this->form($csrf, 'promote', $manifest['version'], Repository::CHANNEL_PROD, 'Promouvoir en Prod', 'primary',
                'Servir cette version à toutes les installations ? Le retour en bêta sera impossible.');
        }
        $html .= '</div></section>';

        return $html;
    }

    private function form(string $csrf, string $action, string $version, ?string $channel, string $label, string $class, string $confirm): string
    {
        return '<form method="post" action="?action=' . self::e($action) . '" data-confirm="' . self::e($confirm) . '">'
            . '<input type="hidden" name="csrf" value="' . self::e($csrf) . '">'
            . '<input type="hidden" name="version" value="' . self::e($version) . '">'
            . ($channel !== null ? '<input type="hidden" name="channel" value="' . self::e($channel) . '">' : '')
            . '<button type="submit" class="' . self::e($class) . '">' . self::e($label) . '</button>'
            . '</form>';
    }

    private function header(string $subtitle): string
    {
        return '<header><h1>' . self::e($this->title) . '</h1><span class="muted">' . self::e($subtitle) . '</span></header>'
            . '<p class="muted"><a href="?action=logout">Déconnexion</a></p>';
    }

    private function page(string $body): string
    {
        return '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<meta name="robots" content="noindex, nofollow">'
            . '<title>' . self::e($this->title) . '</title><style>' . self::STYLE . '</style></head>'
            . '<body><main>' . $body . '</main></body></html>';
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars(is_string($value) ? $value : (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** Erreur d'administration sans fuite d'un chemin ou d'une trace interne. */
    public static function failure(string $message): string
    {
        return '<section class="error" role="alert">' . self::e($message) . '</section>';
    }
}
