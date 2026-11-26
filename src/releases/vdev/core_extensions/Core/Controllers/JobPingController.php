<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Controllers;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobWorker;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\NoTransaction;

/**
 * Déclenchement du worker par le navigateur d'un visiteur.
 *
 * Route anonyme et sans état : elle ne reçoit aucun paramètre, n'ouvre aucune
 * session et ne renvoie qu'un compteur. Elle ne peut ni créer, ni réordonner,
 * ni modifier un job, ni prolonger une tentative ; elle draine seulement ce qui
 * est déjà dû, dans le budget court de la source `traffic`.
 *
 * Aucun jeton, volontairement : un secret lisible dans la page n'apporterait
 * aucune protection. Ce qui borne l'usage est le verrou du worker, la taille du
 * lot et l'intervalle respecté par le navigateur. La route reste soumise au WAF,
 * contrairement aux routes appelées par un CRON externe.
 */
#[Controller(name: 'jobs_ping', isApi: true, path: 'api/jobs/ping', withoutCsrf: true)]
final class JobPingController extends BaseController
{
    #[ControllerMethod(name: 'ping', path: '', methods: [RequestMethod::POST])]
    #[NoTransaction]
    public function ping(): ApiResponse
    {
        $site = $this->request->header('Sec-Fetch-Site', '');

        // Un embarquement depuis un site tiers se reconnaît à cet en-tête quand
        // le navigateur l'envoie. Ce n'est pas une frontière — un client peut le
        // forger — mais cela évite le cas naïf sans rien coûter.
        if (is_string($site) && $site === 'cross-site') {
            return new ApiResponse(['ok' => true, 'ran' => 0]);
        }

        /** @var JobWorker $worker */
        $worker = $this->getSharedExtensionsClass(JobWorker::class);
        $summary = $worker->run(JobWorker::SOURCE_TRAFFIC);

        return new ApiResponse(['ok' => true, 'ran' => $summary['ran']]);
    }
}
