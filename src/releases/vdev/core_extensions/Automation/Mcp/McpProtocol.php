<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Mcp;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationPrincipalType;
use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationHelpers;
use YTassy\Tycms\CoreExts\Core\Helper\ServiceAccountTokenHelper;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Response\FileResponse;
use YTassy\Tycms\Response\Response;
use YTassy\Tycms\WF\BaseHelper;

/**
 * Protocole MCP sur transport Streamable HTTP.
 *
 * Authentification par jeton d'API d'un compte de service, JSON-RPC 2.0 sur un
 * endpoint POST unique, exécution de code via `McpCodeRunner`. Aucun jeton,
 * aucune source et aucun contenu utilisateur n'est journalisé.
 */
final class McpProtocol extends BaseHelper
{
    use TraitAutomationHelpers;

    /** Version MCP négociée par défaut. */
    public const PROTOCOL_VERSION = '2025-06-18';

    /** Versions acceptées en entrée, pour un client plus ancien. */
    private const SUPPORTED_VERSIONS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public const SERVER_NAME = 'tycms';
    public const SERVER_VERSION = '1.0.0';

    /** Plafond glissant : nombre d'appels acceptés par minute et par jeton. */
    public const MAX_REQUESTS_PER_MINUTE = 120;

    /**
     * Résout le jeton d'API de la requête en contexte d'exécution.
     *
     * Le même contrôle sert l'endpoint JSON-RPC et les documents de
     * documentation : un client n'authentifie pas deux fois.
     *
     * @return array{user_id: int, space_id: int, token_id: int}|null
     */
    public function resolveToken(Request $request): ?array
    {
        $token = self::bearerToken($request);

        if ($token === null) {
            return null;
        }

        $tokenRow = $this->tokenHelper()->resolve($token);

        if ($tokenRow === null) {
            $this->logWarning('Jeton MCP refusé.', [], 'MCP_AUTH_FAILED', 'automation');

            return null;
        }

        $userId = (int) $tokenRow['user_id'];
        $spaceId = isset($tokenRow['space_id']) ? (int) $tokenRow['space_id'] : 0;
        $tokenId = (int) $tokenRow['id'];

        // Un jeton ne doit porter que sur un compte de service actif, rattaché
        // à un Space : tout autre cas est un refus générique.
        if ($spaceId <= 0 || !$this->isActiveServiceAccount($userId)) {
            $this->logWarning(
                'Jeton MCP rattaché à un compte ou un Space inutilisable.',
                ['user_id' => $userId],
                'MCP_SERVICE_ACCOUNT_INVALID',
                'automation',
            );

            return null;
        }

        return ['user_id' => $userId, 'space_id' => $spaceId, 'token_id' => $tokenId];
    }

    public function handle(Request $request): Response
    {
        $context = $this->resolveToken($request);

        if ($context === null) {
            return $this->unauthenticated(null);
        }

        $userId = $context['user_id'];
        $spaceId = $context['space_id'];
        $tokenId = $context['token_id'];

        $payload = json_decode($request->rawBody, true);

        if (!is_array($payload) || ($payload['jsonrpc'] ?? null) !== '2.0') {
            return McpJsonRpc::respond(McpJsonRpc::error(null, -32700, 'Requête JSON-RPC invalide.'), 400);
        }

        $id = $payload['id'] ?? null;
        $method = $payload['method'] ?? null;

        // Une notification se reconnaît à l'absence d'identifiant : le protocole
        // MCP envoie ainsi `notifications/initialized` juste après `initialize`.
        $isNotification = !array_key_exists('id', $payload);

        if (!is_string($method)) {
            return McpJsonRpc::respond(McpJsonRpc::error($id, -32600, 'Méthode absente.'), 400);
        }

        // Le plafond porte sur les exécutions de code. Il est vérifié avant le
        // dispatch pour éviter de démarrer un travail coûteux.
        if ($this->exceedsRateLimit($tokenId)) {
            $this->logWarning(
                'Plafond d’appels MCP atteint.',
                ['space_id' => $spaceId, 'token_id' => $tokenId],
                'MCP_RATE_LIMIT',
                'automation',
            );

            return McpJsonRpc::respond(McpJsonRpc::error($id, -32000, 'Trop de demandes.'), 429);
        }

        if ($isNotification) {
            return $this->handleNotification();
        }

        return McpJsonRpc::respond(
            $this->dispatch($id, $method, $payload['params'] ?? null, $userId, $spaceId, $tokenId),
        );
    }

    /**
     * Les notifications du protocole ne reçoivent aucun corps, quelle que soit
     * l'issue. Une méthode inconnue y est ignorée silencieusement.
     */
    private function handleNotification(): Response
    {
        return McpJsonRpc::accepted();
    }

    /**
     * @return array<string, mixed>
     */
    private function dispatch(
        mixed $id,
        string $method,
        mixed $params,
        int $userId,
        int $spaceId,
        int $tokenId,
    ): array {
        return match ($method) {
            'initialize' => McpJsonRpc::result($id, [
                'protocolVersion' => $this->negotiateVersion($params),
                'capabilities' => ['tools' => new \stdClass()],
                'serverInfo' => [
                    'name' => self::SERVER_NAME,
                    'version' => self::SERVER_VERSION,
                ],
            ]),
            'ping' => McpJsonRpc::result($id, new \stdClass()),
            'tools/list' => McpJsonRpc::result($id, ['tools' => $this->toolDefinitions()]),
            'tools/call' => $this->callTool($id, $params, $userId, $spaceId, $tokenId),
            default => McpJsonRpc::error($id, -32601, 'Méthode inconnue : ' . $method . '.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function callTool(mixed $id, mixed $params, int $userId, int $spaceId, int $tokenId): array
    {
        if (!is_array($params) || !is_string($params['name'] ?? null)) {
            return McpJsonRpc::error($id, -32602, 'Paramètres d’outil invalides.');
        }

        $name = $params['name'];
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $catalog = new McpToolCatalog($this->actionRegistry());

        return match ($name) {
            'tycms.run' => $this->runTool($id, $arguments, $userId, $spaceId, $tokenId),
            'tycms.list_actions' => McpJsonRpc::result($id, McpJsonRpc::toolContent([
                'actions' => $catalog->actions(),
            ])),
            'tycms.list_context' => McpJsonRpc::result($id, McpJsonRpc::toolContent([
                'context' => $catalog->context(),
                'spaceId' => $spaceId,
            ])),
            'tycms.reference' => McpJsonRpc::result($id, McpJsonRpc::toolContent($catalog->reference())),
            'tycms.ping' => McpJsonRpc::result($id, McpJsonRpc::toolContent(['ok' => true])),
            default => McpJsonRpc::error($id, -32602, 'Outil inconnu : ' . $name . '.'),
        };
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function runTool(mixed $id, array $arguments, int $userId, int $spaceId, int $tokenId): array
    {
        $source = $arguments['source'] ?? null;

        if (!is_string($source) || trim($source) === '') {
            return McpJsonRpc::error($id, -32602, 'Le paramètre « source » est requis.');
        }

        $input = $arguments['input'] ?? [];

        if (!is_array($input)) {
            return McpJsonRpc::error($id, -32602, 'Le paramètre « input » doit être un objet.');
        }

        try {
            $result = $this->mcpCodeRunner->runCode($source, $userId, $spaceId, $input, $tokenId);
        } catch (\Throwable $exception) {
            // La cause interne reste dans les journaux ; le client ne reçoit
            // jamais de détail de l'infrastructure.
            $this->logError(
                'Exécution MCP impossible.',
                ['space_id' => $spaceId, 'token_id' => $tokenId],
                'MCP_CODE_RUN_FAILED',
                'automation',
            );
            $this->logException($exception);

            return McpJsonRpc::error($id, -32603, 'L’exécution a échoué.');
        }

        // Un run en échec reste une réponse d'outil valide : `isError` signale
        // au client que la sortie n'est pas exploitable.
        return McpJsonRpc::result(
            $id,
            McpJsonRpc::toolContent($result, $result['status'] === 'failed'),
        );
    }

    /** @return list<array<string, mixed>> */
    private function toolDefinitions(): array
    {
        return [
            [
                'name' => 'tycms.run',
                'description' => 'Exécute un programme du langage TyCMS et retourne sa sortie. '
                    . 'Le programme contient une fonction main(). Les actions d’effet externe '
                    . '(e-mail, notifications, Google) sont indisponibles.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'source' => ['type' => 'string', 'description' => 'Programme du langage TyCMS.'],
                        'input' => ['type' => 'object', 'description' => 'Charge utile exposée à $input.'],
                    ],
                    'required' => ['source'],
                ],
            ],
            [
                'name' => 'tycms.list_actions',
                'description' => 'Liste les actions disponibles, leurs paramètres, leurs effets et leurs permissions.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'tycms.list_context',
                'description' => 'Décrit les variables de contexte et les limites du langage.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'tycms.reference',
                'description' => 'Documentation complète du langage, des fonctions et des actions.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
            [
                'name' => 'tycms.ping',
                'description' => 'Vérifie la connexion au serveur MCP.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass()],
            ],
        ];
    }

    /**
     * Le client annonce sa version ; le serveur répond la version commune la
     * plus récente, ou sa propre version si aucune ne correspond.
     */
    private function negotiateVersion(mixed $params): string
    {
        $requested = is_array($params) && is_string($params['protocolVersion'] ?? null)
            ? $params['protocolVersion']
            : null;

        if ($requested !== null && in_array($requested, self::SUPPORTED_VERSIONS, true)) {
            return $requested;
        }

        return self::PROTOCOL_VERSION;
    }

    private function actionRegistry(): ?\YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry
    {
        $plugin = $this->app->getExtensions()['plg_automation'] ?? null;

        return $plugin instanceof \YTassy\Tycms\CoreExts\Automation\Plugin
            ? $plugin->getActionRegistry()
            : null;
    }

    private function tokenHelper(): ServiceAccountTokenHelper
    {
        /** @var ServiceAccountTokenHelper $helper */
        $helper = $this->getSharedExtensionsClass(ServiceAccountTokenHelper::class);

        return $helper;
    }

    private function isActiveServiceAccount(int $userId): bool
    {
        $row = $this->db->selectOneOrNull(
            'SELECT type, blocked FROM users WHERE id = % LIMIT 1',
            [$userId],
        );

        return $row !== null
            && ($row->type ?? '') === AutomationPrincipalType::SERVICE
            && (int) $row->blocked === 0;
    }

    /**
     * Plafond glissant : nombre d'appels acceptés par minute et par jeton.
     *
     * Le comptage s'appuie sur les exécutions enregistrées pour le jeton. Les
     * requêtes de lecture seule (`initialize`, `tools/list`, notifications) ne
     * produisent aucune exécution ; elles restent donc hors plafond. Ce point
     * faible est assumé : elles ne touchent ni contenu ni action, et un
     * plafond dédié demanderait une table de comptage.
     */
    private function exceedsRateLimit(int $tokenId): bool
    {
        $row = $this->db->selectOneOrNull(
            'SELECT COUNT(*) AS total FROM mcp_executions '
                . 'WHERE token_id = % AND created > DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 60 SECOND)',
            [$tokenId],
        );

        return $row !== null && (int) $row->total >= self::MAX_REQUESTS_PER_MINUTE;
    }

    private static function bearerToken(Request $request): ?string
    {
        $header = $request->header('Authorization', '');
        $header = is_string($header) ? trim($header) : '';

        if (preg_match('/\ABearer\s+(.+)\z/i', $header, $matches) !== 1) {
            return null;
        }

        // Un secret d'API fait 64 caractères hexadécimaux ; tout autre format
        // est rejeté avant de toucher la base.
        return preg_match('/\A[0-9a-f]{64}\z/', trim($matches[1])) === 1 ? trim($matches[1]) : null;
    }

    private function unauthenticated(mixed $id): Response
    {
        return McpJsonRpc::respond(
            McpJsonRpc::error($id, -32001, 'Authentification requise.'),
            401,
            ['WWW-Authenticate' => 'Bearer'],
        );
    }

    /**
     * Document Markdown du langage et de l'architecture.
     *
     * Servi par une route GET authentifiée par le même jeton : un humain ou un
     * agent peut lire le guide dans un navigateur sans passer par JSON-RPC.
     */
    public function guideDocument(): Response
    {
        return FileResponse::fromContent(
            McpLanguageGuide::markdown(),
            'tycms-mcp-guide.md',
            'text/markdown; charset=UTF-8',
        );
    }

    /**
     * Référence Markdown complète : guide, fonctions standard et actions.
     *
     * Les actions sont celle du catalogue MCP, donc sans les effets `external`
     * que le serveur refuse de dispatcher.
     */
    public function referenceDocument(): Response
    {
        $catalog = new McpToolCatalog($this->actionRegistry());

        return FileResponse::fromContent(
            McpReferenceMarkdown::render($catalog->reference()),
            'tycms-mcp-reference.md',
            'text/markdown; charset=UTF-8',
        );
    }
}