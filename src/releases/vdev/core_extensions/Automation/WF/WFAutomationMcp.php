<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\WF;

use YTassy\Tycms\CoreExts\Automation\Mcp\McpProtocol;
use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationHelpers;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Response\Response;
use YTassy\Tycms\WF\BaseWF;

/**
 * Point d'entrée métier du serveur MCP.
 *
 * Le workflow ne porte plus le protocole : `McpProtocol` (helpers partagés) le
 * gère. Ce point d'entrée garde la frontière WF pour l'adaptation HTTP et les
 * futurs contrôles transverses.
 */
final class WFAutomationMcp extends BaseWF
{
    use TraitAutomationHelpers;

    public function endpoint(Request $request): Response
    {
        return $this->mcpProtocol->handle($request);
    }
}