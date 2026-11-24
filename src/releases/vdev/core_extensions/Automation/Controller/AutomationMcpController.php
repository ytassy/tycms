<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Controller;

use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationWF;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Security\ExtensionApi\AnnotationsController\SecurityDoNotCheckPatterns;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\Response;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;

/**
 * Endpoint du serveur MCP (JSON-RPC 2.0, transport Streamable HTTP).
 *
 * La route est sans CSRF : elle authentifie le demandeur par jeton d'API. Le
 * WAF est désactivé car elle reçoit du code destiné au langage de scénario.
 * La réponse est un JSON-RPC brut, jamais l'enveloppe API du CMS.
 */
#[Controller(name: 'automation_mcp', isApi: true, path: 'ai/mcp', withoutCsrf: true)]
#[SecurityDoNotCheckPatterns]
final class AutomationMcpController extends BaseController
{
    use TraitAutomationWF;

    #[ControllerMethod(name: 'endpoint', path: '', methods: [RequestMethod::POST])]
    public function endpoint(): Response
    {
        return $this->wfAutomationMcp->endpoint($this->request);
    }
}