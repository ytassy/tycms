<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Controllers;

use YTassy\Tycms\CoreExts\Core\Cron\CronDispatcher;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Security\ExtensionApi\AnnotationsController\SecurityDoNotCheckPatterns;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\NoTransaction;

/**
 * Point d'entrée unique du CRON de l'installation.
 *
 * La route est sans CSRF et sans transaction globale : l'authentification par
 * jeton précède l'appel des hooks, et chaque extension gère ses transactions.
 */
#[Controller(name: 'cron', isApi: true, path: 'cron', withoutCsrf: true)]
#[SecurityDoNotCheckPatterns]
final class CronController extends BaseController
{
    // Le routeur indexe les routes par nom : GET et POST doivent avoir deux
    // noms distincts. Infomaniak déclenche les CRON en GET.
    #[ControllerMethod(name: 'run_get', path: '', methods: [RequestMethod::GET])]
    #[NoTransaction]
    public function runGet(): ApiResponse
    {
        return $this->run();
    }

    #[ControllerMethod(name: 'run_post', path: '', methods: [RequestMethod::POST])]
    #[NoTransaction]
    public function runPost(): ApiResponse
    {
        return $this->run();
    }

    private function run(): ApiResponse
    {
        /** @var CronDispatcher $dispatcher */
        $dispatcher = $this->getSharedExtensionsClass(CronDispatcher::class);

        return new ApiResponse($dispatcher->run($this->request));
    }
}
