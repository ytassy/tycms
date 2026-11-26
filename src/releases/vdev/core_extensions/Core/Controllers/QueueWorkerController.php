<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Controllers;

use YTassy\Tycms\CoreExts\Core\Cron\TokenGuard;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\JobQueue\JobWorker;
use YTassy\Tycms\CoreExts\Security\ExtensionApi\AnnotationsController\SecurityDoNotCheckPatterns;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\NoTransaction;

/**
 * Déclencheur HTTP du worker de la file de jobs.
 *
 * Route distincte de `/cron` : la file a son propre secret et son propre budget,
 * pour qu'une file chargée n'affame pas les traitements planifiés du système.
 * GET est accepté parce que certains hébergeurs ne déclenchent les CRON qu'en
 * GET. Aucune transaction globale : le worker gère la sienne par job.
 */
#[Controller(name: 'queue_worker', isApi: true, path: 'queue-worker', withoutCsrf: true)]
#[SecurityDoNotCheckPatterns]
final class QueueWorkerController extends BaseController
{
    // Le routeur indexe les routes par nom : GET et POST ont deux noms.
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
        $conf = $this->app->getConf();

        /** @var TokenGuard $guard */
        $guard = $this->getSharedExtensionsClass(TokenGuard::class);
        $guard->assert(
            $this->request,
            (string) ($conf->job_worker_token ?? ''),
            'job_worker_token',
            'file de jobs',
            'JOB_WORKER',
            'jobs',
        );

        /** @var JobWorker $worker */
        $worker = $this->getSharedExtensionsClass(JobWorker::class);

        return new ApiResponse(['ok' => true] + $worker->run(JobWorker::SOURCE_CRON));
    }
}
