<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Security\Checks;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\ISecurityCheck;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\SecurityFinding;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobAdminHelper;

/**
 * Drainage de la file de jobs.
 *
 * Deux pannes silencieuses guettent une installation mutualisée : une route de
 * worker sans jeton, et un second CRON jamais configuré. Le contrôle ne
 * signale rien tant qu'aucun job n'attend, pour ne pas alerter une installation
 * qui n'utilise pas encore la file.
 */
final class JobWorkerCheck extends BaseHelper implements ISecurityCheck
{
    /** Même seuil que l'écran Jobs : au-delà, le worker est considéré arrêté. */
    private const STALE_SECONDS = 300;

    public function group(): string
    {
        return 'File de jobs';
    }

    public function check(): array
    {
        $conf = $this->app->getConf();
        $token = trim((string) ($conf->job_worker_token ?? ''));
        $traffic = ($conf->job_traffic_trigger ?? false) === true;

        /** @var JobAdminHelper $helper */
        $helper = $this->getSharedExtensionsClass(JobAdminHelper::class);

        $state = $helper->workerState();
        $pending = $this->pendingJobs();
        $stale = $state['lastRunAt'] === null
            || strtotime((string) $state['lastRunAt']) < time() - self::STALE_SECONDS;

        $findings = [];

        // Rien à signaler tant qu'aucun job n'attend : une file vide n'a pas
        // besoin d'être drainée.
        if ($pending === 0 && $state['lastRunAt'] === null) {
            return [new SecurityFinding(
                'File de jobs',
                SecurityFinding::OK,
                'Aucun job en attente et aucun passage du worker ; rien ne requiert encore le second CRON.',
            )];
        }

        if ($token === '') {
            $findings[] = new SecurityFinding(
                'Worker de la file sans jeton',
                $pending > 0 ? SecurityFinding::DANGER : SecurityFinding::INFO,
                'Sans `job_worker_token`, la route `/queue-worker` répond `503` et la file n’est drainée '
                . 'que par le trafic du site. Sur un site peu visité, des jobs peuvent attendre longtemps.',
                'Définissez `job_worker_token` avec une valeur aléatoire longue, puis ajoutez la seconde ligne de CRON.',
                'configuration.php — job_worker_token',
            );
        } else {
            $findings[] = new SecurityFinding(
                'Worker de la file protégé',
                SecurityFinding::OK,
                'La route du worker de la file exige un jeton.',
            );
        }

        if ($stale && $pending > 0) {
            $findings[] = new SecurityFinding(
                'Worker de la file inactif',
                SecurityFinding::DANGER,
                'Des jobs attendent alors qu’aucun passage du worker n’a eu lieu depuis plus de cinq minutes.',
                'Vérifiez la seconde ligne de CRON appelant `/queue-worker`, ou activez `job_traffic_trigger`.',
                'Cron de la file et configuration.php',
            );
        } elseif (!$traffic && $pending > 0) {
            $findings[] = new SecurityFinding(
                'Déclenchement par le trafic désactivé',
                SecurityFinding::INFO,
                'Seul le CRON de la file peut drainer les jobs en attente.',
                'Activez `job_traffic_trigger` si le CRON n’est pas fiable.',
                'configuration.php — job_traffic_trigger',
            );
        }

        return $findings;
    }

    private function pendingJobs(): int
    {
        $row = $this->db->selectOneOrNull(
            "SELECT COUNT(*) AS total FROM jobs WHERE status = 'pending'",
        );

        return $row === null ? 0 : (int) $row->total;
    }
}
