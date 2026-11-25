<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\WF;

use YTassy\Tycms\CoreExts\Automation\Mcp\McpProtocol;
use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationHelpers;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits\TraitCoreSharedHelpers;
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
    use TraitCoreSharedHelpers;
    use TraitAutomationHelpers;

    public function endpoint(Request $request): Response
    {
        return $this->mcpProtocol->handle($request);
    }

    /**
     * Guide Markdown, servi après vérification du même jeton que l'endpoint.
     *
     * L'authentification est faite ici, dans le WF : la route GET reste sans
     * session ni CSRF, comme `POST /ai/mcp`.
     */
    public function guide(Request $request): Response
    {
        return $this->authenticated($request, fn (): Response => $this->mcpProtocol->guideDocument());
    }

    /** Référence Markdown complète (guide, fonctions standard, actions). */
    public function reference(Request $request): Response
    {
        return $this->authenticated($request, fn (): Response => $this->mcpProtocol->referenceDocument());
    }

    /**
     * Enveloppe d'authentification des documents.
     *
     * Deux modes, strictement distincts, sont acceptés pour lire la
     * documentation (routes GET, sans effet, donc sans CSRF) :
     *
     * - mode session : une session ROOT ouverte, pour la lecture humaine dans
     *   un navigateur ; le jeton n'est alors pas exigé ;
     * - mode jeton : un jeton d'API de compte de service, pour un client MCP.
     *
     * Aucun mode ne s'additionne à l'autre : chacun suffit seul, et la même
     * requête qui échoue aux deux est refusée en 401. Une session ordinaire ne
     * suffit jamais, la documentation décrivant le moteur et ses actions.
     *
     * @param callable(): Response $document
     */
    private function authenticated(Request $request, callable $document): Response
    {
        // Mode session ROOT : aucun jeton n'est lu dans ce cas.
        if ($this->isRootSession()) {
            return $document();
        }

        // Mode jeton : la seule preuve d'identité restante.
        if ($this->hasApiToken($request)) {
            return $document();
        }

        return $this->authenticationRequired();
    }

    /**
     * Une session ROOT ouverte donne lecture sans jeton.
     *
     * `AclHelper` résout le principal depuis la session de l'Application ;
     * hors exécution MCP, c'est la session HTTP du navigateur.
     */
    private function isRootSession(): bool
    {
        try {
            return $this->coreAclHelper->isRoot();
        } catch (\Throwable) {
            // Aucune session utilisable : on passe au mode jeton, sans exposer
            // la cause au client.
            return false;
        }
    }

    /**
     * Le jeton d'API est la seule preuve demandée en mode jeton.
     *
     * `resolveToken()` vérifie le hachage, l'expiration, la révocation et le
     * statut du compte de service : le mode jeton n'admet rien de moins.
     */
    private function hasApiToken(Request $request): bool
    {
        return $this->mcpProtocol->resolveToken($request) !== null;
    }

    private function authenticationRequired(): Response
    {
        return new Response(
            ['Content-Type' => 'text/plain; charset=utf-8', 'WWW-Authenticate' => 'Bearer'],
            'Authentification requise : session ROOT ou jeton d’API.',
            401,
        );
    }
}