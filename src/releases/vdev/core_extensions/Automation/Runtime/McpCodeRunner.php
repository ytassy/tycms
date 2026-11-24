<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime;

use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationPrincipalType;
use YTassy\Tycms\CoreExts\Automation\Language\Diagnostic;
use YTassy\Tycms\CoreExts\Automation\Language\Limits;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\BuiltinFunctionCatalog;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionEffect;
use YTassy\Tycms\CoreExts\Automation\Language\ScenarioCompiler;
use YTassy\Tycms\CoreExts\Automation\Language\SourcePosition;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\Action\RegistryActionDispatcher;
use YTassy\Tycms\CoreExts\Automation\Runtime\Builtin\BuiltinFunctionRegistry;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionStatus;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Session\RunSession;

/**
 * Exécution de code ad hoc hors scénario (serveur MCP).
 *
 * Compile et interprète un programme du langage avec l'identité d'un compte de
 * service, dans une Application isolée et une transaction unique. Les actions à
 * effet `external` sont exclues, et chaque exécution est auditée dans
 * `mcp_executions`. La source n'est jamais conservée, seul son hachage l'est.
 */
final class McpCodeRunner extends BaseHelper
{
    private const TRIGGER_ID = 'mcp.code.executed';

    /**
     * @param array<mixed> $input
     * @return array<string, mixed>
     */
    public function runCode(
        string $source,
        int $userId,
        int $spaceId,
        array $input,
        ?int $tokenId = null,
    ): array {
        // L'entrée traverse la frontière de valeurs avant toute exécution.
        $safeInput = ValueTools::assertSafe($input, new Limits(), self::neutralSpan());

        if (!is_array($safeInput)) {
            throw new \RuntimeException('Entrée de code invalide.');
        }

        $sourceHash = hash('sha256', $source);
        $startedAt = microtime(true);

        // La ligne d'audit est créée avant l'exécution : elle subsiste même si
        // le run échoue, et fournit son identifiant à l'ActionContext.
        $executionId = $this->persistRunning($tokenId, $userId, $spaceId, $sourceHash, $safeInput);

        $principal = $this->loadPrincipal($userId);
        $space = $this->loadSpace($spaceId);
        $executionApp = $this->createRunApplication($userId, $spaceId);

        // Les actions à effet externe (e-mail, notifications, Google…) sont
        // exclues du catalogue : l'IA ne peut même pas les nommer.
        $registry = $this->actionRegistryFor($executionApp);
        $excluded = [FunctionEffect::EXTERNAL];

        $limits = new Limits();
        $catalog = new AutomationFunctionCatalog(BuiltinFunctionCatalog::create(), $registry, $excluded);
        $compiled = (new ScenarioCompiler($catalog, $limits))->compile($source);

        if (!$compiled->isSuccessful() || $compiled->program === null) {
            $diagnostic = $compiled->diagnostics[0] ?? null;

            $this->persistFinished(
                $executionId,
                'failed',
                null,
                $diagnostic?->code ?? 'COMPILE_ERROR',
                $diagnostic?->message ?? 'Compilation échouée.',
                microtime(true) - $startedAt,
                0,
                0,
            );

            return [
                'execution_id' => $executionId,
                'status' => 'failed',
                'output' => null,
                'message' => $diagnostic?->message ?? 'Compilation échouée.',
                'diagnostics' => array_map(
                    static fn (Diagnostic $item): string => (string) $item,
                    $compiled->diagnostics,
                ),
            ];
        }

        $dispatcher = new RegistryActionDispatcher($executionApp, $registry, $excluded);
        $context = ExecutionContext::create(
            $safeInput,
            $principal,
            $space,
            [
                'id' => $executionId,
                'scenarioId' => 0,
                'versionId' => 0,
                'trigger' => self::TRIGGER_ID,
            ],
            $dispatcher,
        );

        // Application isolée : sa connexion est distincte de celle de la route.
        // Les actions écrivent donc sur `$database` ; c'est CETTE connexion que
        // la transaction unique encadre. La transaction de la route (ouverte par
        // le runner) ne voit que la ligne d'audit, écrite sur la connexion
        // courante, et reste indépendante.
        $database = $executionApp->getDatabase();
        $database->execute('START TRANSACTION');

        try {
            $result = (new Interpreter($catalog, new BuiltinFunctionRegistry(), $limits))
                ->execute($compiled->program, $context);

            // Un run arrêté sur `fail()` a produit des écritures : elles sont
            // annulées, l'échec ne laisse pas d'état partiel.
            if ($result->status === ExecutionStatus::FAILED) {
                $database->execute('ROLLBACK');
            } else {
                $database->execute('COMMIT');
            }
        } catch (\Throwable $exception) {
            $database->execute('ROLLBACK');

            $this->logError(
                'Exécution MCP interrompue.',
                ['space_id' => $spaceId, 'token_id' => $tokenId],
                'MCP_CODE_RUN_FAILED',
                'automation',
            );
            $this->logException($exception);

            $this->persistFinished(
                $executionId,
                'failed',
                null,
                'RUNTIME_ERROR',
                'Erreur interne de l’exécution.',
                microtime(true) - $startedAt,
                0,
                0,
            );

            return [
                'execution_id' => $executionId,
                'status' => 'failed',
                'output' => null,
                'message' => 'Erreur interne de l’exécution.',
                'diagnostics' => [],
            ];
        }

        $status = $result->status->value;
        $diagnostic = $result->diagnostics[0] ?? null;

        $this->persistFinished(
            $executionId,
            $status,
            $result->output,
            $diagnostic?->code,
            $diagnostic?->message,
            microtime(true) - $startedAt,
            $result->journal->count(),
            $result->actionCalls,
        );

        return [
            'execution_id' => $executionId,
            'status' => $status,
            'output' => $result->output,
            'message' => $result->message,
            'diagnostics' => array_map(
                static fn (Diagnostic $item): string => (string) $item,
                $result->diagnostics,
            ),
        ];
    }

    private function persistRunning(?int $tokenId, int $userId, int $spaceId, string $sourceHash, array $input): int
    {
        $this->db->execute(
            'INSERT INTO mcp_executions (token_id, user_id, space_id, source_hash, input, status) VALUES (%, %, %, %, %, \'running\')',
            [$tokenId, $userId, $spaceId, $sourceHash, AutomationValueJson::encode($input)],
        );

        return (int) $this->db->getLastInsertId();
    }

    private function persistFinished(
        int $executionId,
        string $status,
        mixed $output,
        ?string $errorCode,
        ?string $errorMessage,
        float $durationSeconds,
        int $stepsCount,
        int $actionCalls,
    ): void {
        $this->db->execute(
            'UPDATE mcp_executions SET status = %, output = %, error_code = %, error_message = %, '
                . 'duration_ms = %, steps_count = %, action_calls = %, finished_at = CURRENT_TIMESTAMP WHERE id = %',
            [
                $status,
                $output === null ? null : AutomationValueJson::encode($output),
                $errorCode,
                $errorMessage,
                (int) round($durationSeconds * 1000),
                $stepsCount,
                $actionCalls,
                $executionId,
            ],
        );
    }

    private function loadPrincipal(int $userId): array
    {
        $row = $this->db->selectOneOrNull(
            'SELECT id, type, first_name, last_name, email FROM users WHERE id = % LIMIT 1',
            [$userId],
        );

        if ($row === null) {
            throw new \RuntimeException('Principal introuvable.');
        }

        return [
            'id' => (int) $row->id,
            'type' => ($row->type ?? '') === 'service'
                ? AutomationPrincipalType::SERVICE
                : AutomationPrincipalType::USER,
            'firstName' => (string) $row->first_name,
            'lastName' => (string) $row->last_name,
            'email' => (string) $row->email,
        ];
    }

    /** @return array<string, mixed> */
    private function loadSpace(int $spaceId): array
    {
        $space = $this->coreSpaceHelper->getSpace($spaceId);

        if ($space === null) {
            throw new \RuntimeException('Space introuvable.');
        }

        $params = $space->params;

        if (is_string($params)) {
            $decoded = json_decode($params, true);
            $params = is_array($decoded) ? $decoded : [];
        }

        if (!is_array($params)) {
            $params = [];
        }

        return [
            'id' => $spaceId,
            'name' => (string) $space->title,
            'timezone' => is_string($params['timezone'] ?? null)
                ? $params['timezone']
                : date_default_timezone_get(),
        ];
    }

    private function createRunApplication(int $userId, int $spaceId): Application
    {
        $configuration = clone $this->app->getConf();
        $configuration->RESET_DATABASE = false;

        $application = new Application($configuration);
        $session = new RunSession($application, $userId, $spaceId);
        $application->initialiseOrThrow(null, false, $session, false);

        return $application;
    }

    private function actionRegistryFor(Application $application): ?AutomationActionRegistry
    {
        $plugin = $application->getExtensions()['plg_automation'] ?? null;

        return $plugin instanceof \YTassy\Tycms\CoreExts\Automation\Plugin ? $plugin->getActionRegistry() : null;
    }

    private static function neutralSpan(): SourceSpan
    {
        $position = new SourcePosition(0, 1, 1);

        return new SourceSpan($position, $position);
    }
}
