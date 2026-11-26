<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\JobQueue;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Tools\HtmlTools;

/**
 * Déclenchement du worker de la file par le trafic du site.
 *
 * Le rendu d'une page publique porte un appel asynchrone, uniquement si un job
 * est dû : un site au repos ne paie qu'une requête indexée, sans script ni
 * appel réseau. L'appel ne crée aucun travail ; il accélère seulement le
 * drainage de ce qui attend déjà.
 *
 * Aucun jeton : la route appelée n'accorde aucune autorité, elle ne renvoie que
 * des compteurs. Le navigateur limite aussi la fréquence, pour ne pas payer un
 * démarrage PHP à chaque page vue.
 */
final class JobTrafficTrigger extends BaseHelper
{
    /** Route technique appelée par le navigateur. */
    public const PING_PATH = '/api/jobs/ping';

    /** Intervalle minimal entre deux tentatives d'un même onglet, en millisecondes. */
    public const THROTTLE_MS = 300000;

    /** Clé de stockage local du limiteur. */
    public const STORAGE_KEY = 'tycms-jobs-ping';

    /** Balise à insérer dans le document, ou chaîne vide. */
    public function tag(): string
    {
        // Un site peut s'en remettre au seul CRON de la file.
        if (($this->app->getConf()->job_traffic_trigger ?? false) !== true) {
            return '';
        }

        if (!$this->hasDueWork()) {
            return '';
        }

        // Le script est en ligne : sans nonce CSP il serait bloqué, donc inerte
        // et trompeur. Mieux vaut ne rien émettre.
        $nonce = $this->coreFrontendAssets->cspNonce();

        return $nonce === null ? '' : self::render($nonce);
    }

    /**
     * Produit la balise complète. Méthode pure, séparée de la configuration et
     * de la base pour que son contrat soit vérifiable sans Application.
     */
    public static function render(string $nonce, int $throttleMs = self::THROTTLE_MS): string
    {
        $nonce = HtmlTools::escape($nonce);
        $path = self::PING_PATH;
        $key = self::STORAGE_KEY;

        // Pas de dépendance, pas d'attente, aucune erreur remontée : ce script
        // ne doit jamais retarder ni gêner l'affichage de la page.
        return <<<HTML
<script nonce="{$nonce}">
(function () {
    var key = '{$key}';
    try {
        var last = parseInt(sessionStorage.getItem(key) || '0', 10) || 0;
        if (Date.now() - last < {$throttleMs}) { return; }
        sessionStorage.setItem(key, String(Date.now()));
    } catch (error) {
        // Stockage indisponible : le serveur plafonne de toute façon le worker.
    }
    var send = function () {
        try {
            fetch('{$path}', { method: 'POST', credentials: 'omit', keepalive: true })
                .catch(function () { /* déclenchement au mieux, aucune conséquence */ });
        } catch (error) {
            // Aucun effet sur la page.
        }
    };
    if ('requestIdleCallback' in window) {
        requestIdleCallback(send, { timeout: 4000 });
    } else {
        setTimeout(send, 1500);
    }
})();
</script>
HTML;
    }

    private function hasDueWork(): bool
    {
        // La table est créée par la migration de la file, jouée par l'updater
        // avant l'activation de la release : aucun repli sur une base non
        // migrée n'est nécessaire, et une erreur doit rester visible.
        /** @var JobQueue $queue */
        $queue = $this->getSharedExtensionsClass(JobQueue::class);

        return $queue->hasDueWork();
    }
}
