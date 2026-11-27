<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Controllers\Admin;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\AnnotationsController\Acl;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Core\WF\WFMailLog;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;

/**
 * API d'administration du journal des e-mails, réservée à ROOT.
 *
 * Le contrôleur reste mince : l'autorisation et les seuils sont dans `WFMailLog`.
 */
#[Controller(name: 'administrator_api_mail_logs_', isApi: true, path: 'administrator/api/mail-logs')]
final class AdminMailLogController extends BaseController
{
    #[ControllerMethod(name: 'list', path: '', methods: [RequestMethod::GET])]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function list(): ApiResponse
    {
        return new ApiResponse($this->wfMailLog()->list());
    }

    #[ControllerMethod(name: 'status', path: 'status', methods: [RequestMethod::GET])]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function status(): ApiResponse
    {
        return new ApiResponse($this->wfMailLog()->status());
    }

    #[ControllerMethod(name: 'detail', path: '{id}', methods: [RequestMethod::GET], where: ['id' => '\d+'])]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function detail(int $id): ApiResponse
    {
        return new ApiResponse(['item' => $this->wfMailLog()->detail($id)]);
    }

    #[ControllerMethod(name: 'purge', path: 'purge', methods: [RequestMethod::POST])]
    #[Acl(globalPermission: Permissions::ROOT, spaceId: 1)]
    public function purge(): ApiResponse
    {
        $days = filter_var($this->request->body['days'] ?? null, FILTER_VALIDATE_INT);

        return new ApiResponse([
            'deleted' => $this->wfMailLog()->purge($days === false ? -1 : (int) $days),
        ]);
    }

    private function wfMailLog(): WFMailLog
    {
        /** @var WFMailLog $wf */
        $wf = $this->getSharedExtensionsClass(WFMailLog::class);

        return $wf;
    }
}
