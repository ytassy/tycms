<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Controllers\Admin;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\AnnotationsController\Acl;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\WF\WFJobs;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;

/**
 * Interface d'administration de la file de jobs, réservée à ROOT.
 *
 * Le contrôleur reste mince : filtrage, autorisation et journalisation sont
 * dans `WFJobs`. Les actions POST héritent du contrôle CSRF des routes API.
 */
#[Controller(name: 'administrator_api_jobs_', isApi: true, path: 'administrator/api/jobs')]
final class AdminJobsController extends BaseController
{
    #[ControllerMethod(name: 'list', path: '', methods: [RequestMethod::GET])]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function list(): ApiResponse
    {
        // Les filtres viennent de la query : une valeur inconnue est ignorée par
        // le WF, jamais concaténée au SQL.
        $status = $this->request->query('status');
        $type = $this->request->query('type');
        $spaceId = $this->request->query('space_id');
        $page = $this->request->query('page');

        return new ApiResponse($this->wfJobs()->list(
            is_string($status) && $status !== '' ? $status : null,
            is_string($type) && $type !== '' ? $type : null,
            is_numeric($spaceId) ? (int) $spaceId : null,
            is_numeric($page) ? (int) $page : 1,
        ));
    }

    #[ControllerMethod(name: 'state', path: 'state', methods: [RequestMethod::GET])]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function state(): ApiResponse
    {
        return new ApiResponse($this->wfJobs()->list(null, null, null, 1)['worker'] ?? []);
    }

    #[ControllerMethod(name: 'documentation', path: 'documentation', methods: [RequestMethod::GET])]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function documentation(): ApiResponse
    {
        return new ApiResponse($this->wfJobs()->documentation());
    }

    #[ControllerMethod(name: 'detail', path: '{id}', methods: [RequestMethod::GET], where: ['id' => '\d+'])]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function detail(int $id): ApiResponse
    {
        return new ApiResponse($this->wfJobs()->detail($id));
    }

    #[ControllerMethod(name: 'retry', path: '{id}/retry', methods: [RequestMethod::POST], where: ['id' => '\d+'])]
    public function retry(int $id): ApiResponse
    {
        return new ApiResponse(['retried' => $this->wfJobs()->retry($id)]);
    }

    #[ControllerMethod(name: 'cancel', path: '{id}/cancel', methods: [RequestMethod::POST], where: ['id' => '\d+'])]
    public function cancel(int $id): ApiResponse
    {
        return new ApiResponse(['cancelled' => $this->wfJobs()->cancel($id)]);
    }

    #[ControllerMethod(name: 'run', path: 'run', methods: [RequestMethod::POST])]
    public function run(): ApiResponse
    {
        return new ApiResponse($this->wfJobs()->run());
    }

    #[ControllerMethod(name: 'purge', path: 'purge', methods: [RequestMethod::POST])]
    public function purge(): ApiResponse
    {
        $days = $this->request->body['days'] ?? 14;

        return new ApiResponse(['deleted' => $this->wfJobs()->purge(is_numeric($days) ? (int) $days : 14)]);
    }

    private function wfJobs(): WFJobs
    {
        /** @var WFJobs $wf */
        $wf = $this->getSharedExtensionsClass(WFJobs::class);

        return $wf;
    }
}
