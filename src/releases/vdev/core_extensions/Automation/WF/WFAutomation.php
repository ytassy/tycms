<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\WF;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationParameterDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationPayloadFieldDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationPrincipalMode;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationTriggerDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationTriggerRegistry;
use YTassy\Tycms\CoreExts\Automation\Language\Ast\Program;
use YTassy\Tycms\CoreExts\Automation\Language\CompilationResult;
use YTassy\Tycms\CoreExts\Automation\Language\Diagnostic;
use YTassy\Tycms\CoreExts\Automation\Language\Limits;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\BuiltinFunctionCatalog;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\BuiltinFunctionReference;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\BuiltinStatementReference;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\ContextVariableCatalog;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionEffect;
use YTassy\Tycms\CoreExts\Automation\Language\ScenarioCompiler;
use YTassy\Tycms\CoreExts\Automation\Language\SourcePosition;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Permissions;
use YTassy\Tycms\CoreExts\Automation\Plugin;
use YTassy\Tycms\CoreExts\Automation\Reference\AutomationReferenceDocument;
use YTassy\Tycms\CoreExts\Automation\Runtime\Action\ActionReferenceCollector;
use YTassy\Tycms\CoreExts\Automation\Runtime\Action\DryRunActionDispatcher;
use YTassy\Tycms\CoreExts\Automation\Runtime\Action\RegistryActionDispatcher;
use YTassy\Tycms\CoreExts\Automation\Runtime\AutomationFunctionCatalog;
use YTassy\Tycms\CoreExts\Automation\Runtime\AutomationValueJson;
use YTassy\Tycms\CoreExts\Automation\Runtime\Builtin\BuiltinFunctionRegistry;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionContext;
use YTassy\Tycms\CoreExts\Automation\Runtime\Interpreter;
use YTassy\Tycms\CoreExts\Automation\Runtime\ValueTools;
use YTassy\Tycms\CoreExts\Automation\Maintenance\MaintenancePolicy;
use YTassy\Tycms\CoreExts\Automation\Schedule\ScheduleCalculator;
use YTassy\Tycms\CoreExts\Automation\Schedule\ScheduleConfig;
use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationHelpers;
use YTassy\Tycms\CoreExts\Automation\Webhook\WebhookAuthMode;
use YTassy\Tycms\CoreExts\Automation\Webhook\WebhookConfig;
use YTassy\Tycms\CoreExts\Automation\Webhook\WebhookReceiver;
use YTassy\Tycms\CoreExts\Automation\Webhook\WebhookSecrets;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits\TraitCoreSharedHelpers;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

final class WFAutomation extends BaseWF
{
    use TraitCoreSharedHelpers;
    use TraitAutomationHelpers;

    private const SCHEDULE_TRIGGER = 'automation.schedule.tick';

    private const DEFAULT_SOURCE = <<<'TXT'
function main() {
    // Décrivez le scénario ici. $input contient les données du déclencheur.
    return null;
}
TXT;

    private const MAX_SOURCE_BYTES = 65536;

    /**
     * @param array<mixed> $input
     * @return array<string, mixed>
     */
    public function runManual(int $scenarioId, array $input): array
    {
        $scenario = $this->automationRepository->findScenarioById($scenarioId);

        if ($scenario === null) {
            ExceptionResponse::throwWarning('Scénario introuvable.');
        }

        $spaceId = (int) $scenario->space_id;

        if (!$this->coreAclHelper->hasGlobalPermission(Permissions::RUN, $spaceId)) {
            ExceptionResponse::throwDanger('Vous ne pouvez pas exécuter ce scénario.');
        }

        $userId = $this->session->getUserId();

        if ($userId === null) {
            ExceptionResponse::throwDanger('Vous devez être connecté pour exécuter un scénario.');
        }

        if ($input !== [] && array_is_list($input)) {
            ExceptionResponse::throwWarning('L’entrée du scénario doit être une map JSON.');
        }

        return $this->automationRunExecutor->runManual($scenarioId, $input, $userId);
    }

    /**
     * Relance un run en échec avec la version publiée courante.
     *
     * L'entrée du run d'origine est réutilisée ; la version brouillon n'est
     * jamais exécutée. La permission RUN_MANAGE évite de rejouer l'entrée d'un
     * autre utilisateur depuis le simple droit d'exécution.
     *
     * @return array<string, mixed>
     */
    public function rerunRun(int $runId): array
    {
        $run = $this->automationRepository->findRunById($runId);

        if ($run === null) {
            ExceptionResponse::throwWarning('Run introuvable.');
        }

        $spaceId = (int) $run->scenario_space_id;
        $this->assertPermission(Permissions::RUN_MANAGE, $spaceId);

        if (!in_array((string) $run->status, ['failed', 'stopped'], true)) {
            ExceptionResponse::throwWarning('Seuls les runs en échec ou arrêtés peuvent être relancés.');
        }

        $input = json_decode((string) $run->input, true);

        if (!is_array($input)) {
            ExceptionResponse::throwWarning('L’entrée du run d’origine est invalide.');
        }

        try {
            $result = $this->runManual((int) $run->scenario_id, $input);
        } catch (ExceptionResponse $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->logError(
                'La relance d’un run a échoué.',
                ['space_id' => $spaceId, 'source_run_id' => $runId],
                'AUTOMATION_RUN_RERUN_FAILED',
                'automation',
            );
            $this->logException($exception);

            ExceptionResponse::throwWarning('La relance du run a échoué.');
        }

        $this->logInfo(
            'Run relancé depuis l’historique.',
            [
                'space_id' => $spaceId,
                'source_run_id' => $runId,
                'run_id' => $result['run_id'] ?? null,
                'status' => $result['status'] ?? null,
            ],
            'AUTOMATION_RUN_RERUN',
            'automation',
        );

        return $result;
    }

    /** @return array<string, mixed> */
    public function getDirectory(): array
    {
        $spaceIds = array_values(array_map(
            static fn (mixed $id): int => (int) $id,
            $this->coreAclHelper->getSpacesHavingGlobalPermission(Permissions::VIEW),
        ));

        if ($spaceIds === []) {
            ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour consulter les automatisations.');
        }

        $spaces = [];

        foreach ($spaceIds as $spaceId) {
            $space = $this->coreSpaceHelper->getSpace($spaceId);

            if ($space === null) {
                continue;
            }

            $spaces[] = [
                'id' => $spaceId,
                'title' => (string) $space->title,
                'timezone' => ScheduleCalculator::spaceTimezone($space->params ?? null),
                'can_create' => $this->can(Permissions::EDIT, $spaceId),
                'can_publish' => $this->can(Permissions::PUBLISH, $spaceId),
                'can_run' => $this->can(Permissions::RUN, $spaceId),
                'can_manage_runs' => $this->can(Permissions::RUN_MANAGE, $spaceId),
            ];
        }

        $scenarios = array_map(
            fn (object $row): array => $this->presentScenarioRow($row),
            $this->automationRepository->listScenarios($spaceIds),
        );

        return [
            'spaces' => $spaces,
            'scenarios' => $scenarios,
            'triggers' => $this->presentTriggerDefinitions(),
            'actions' => $this->presentActionDefinitions(),
            'builtins' => $this->builtinReference(),
            'statements' => BuiltinStatementReference::all(),
            'context' => ContextVariableCatalog::base(),
            'template' => self::DEFAULT_SOURCE,
        ];
    }

    /** @return array<string, mixed> */
    public function getScenario(int $scenarioId): array
    {
        $scenario = $this->requireScenario($scenarioId);
        $spaceId = (int) $scenario->space_id;
        $this->assertCanSee($spaceId);

        $trigger = $this->automationRepository->findTrigger($scenarioId);
        $draft = $scenario->draft_version_id !== null
            ? $this->automationRepository->findVersion((int) $scenario->draft_version_id)
            : null;
        $published = $this->automationRepository->findPublishedVersion($scenarioId);
        $source = $draft !== null
            ? (string) $draft->source
            : ($published !== null ? (string) $published->source : self::DEFAULT_SOURCE);
        $space = $this->coreSpaceHelper->getSpace($spaceId);
        $presentedTrigger = $trigger !== null
            ? $this->presentTrigger($trigger, $scenario, $space)
            : null;
        $triggerId = $trigger !== null ? (string) $trigger->trigger_id : null;
        $isWebhook = $triggerId === WebhookReceiver::TRIGGER_ID;
        $isSchedule = $triggerId === self::SCHEDULE_TRIGGER;

        return [
            'id' => (int) $scenario->id,
            'space_id' => $spaceId,
            'space_title' => $space?->title ?? '',
            'space_timezone' => $this->spaceTimezone($space),
            'key' => (string) $scenario->key,
            'name' => (string) $scenario->name,
            'description' => (string) $scenario->description,
            'status' => (string) $scenario->status,
            'principal_mode' => (string) $scenario->principal_mode,
            'trigger_id' => $triggerId,
            'trigger' => $presentedTrigger,
            'trigger_config' => $presentedTrigger['config'] ?? [],
            'trigger_enabled' => $trigger !== null && (int) $trigger->enabled === 1,
            'auth_mode' => $isWebhook ? ($presentedTrigger['auth_mode'] ?? null) : null,
            'webhook_url' => $isWebhook ? ($presentedTrigger['webhook_url'] ?? null) : null,
            'secret_configured' => $isWebhook
                ? (bool) ($presentedTrigger['secret_configured'] ?? false)
                : false,
            'schedule_next_run' => $isSchedule
                ? ($presentedTrigger['next_run'] ?? null)
                : null,
            'source' => $source,
            'draft' => $this->presentVersion($draft),
            'published' => $this->presentVersion($published),
            'is_dirty' => $scenario->published_version_id === null
                || (int) $scenario->draft_version_id !== (int) $scenario->published_version_id,
            'execution_user' => $scenario->execution_user_id !== null
                ? $this->presentUser((int) $scenario->execution_user_id)
                : null,
            'can_edit' => $this->can(Permissions::EDIT, $spaceId),
            'can_publish' => $this->can(Permissions::PUBLISH, $spaceId),
            'can_run' => $this->can(Permissions::RUN, $spaceId) && $published !== null,
            'can_manage_runs' => $this->can(Permissions::RUN_MANAGE, $spaceId),
            'can_archive' => $this->can(Permissions::PUBLISH, $spaceId),
            'template' => self::DEFAULT_SOURCE,
        ];
    }

    /**
     * Scénarios publiés abonnés à un trigger, pour les éditeurs producteurs.
     *
     * Le producteur fournit l'identifiant de son trigger ; le moteur ne connaît
     * ni son extension ni son interface.
     *
     * @return array<string, mixed>
     */
    public function getScenarioChoices(int $spaceId, string $triggerId): array
    {
        $this->requireSpace($spaceId);
        $this->assertPermission(Permissions::VIEW, $spaceId);
        $definition = $this->triggerRegistry()?->find($triggerId);

        if ($definition === null) {
            ExceptionResponse::throwWarning('Déclencheur indisponible.');
        }

        $scenarios = array_map(
            fn (object $row): array => [
                'id' => (int) $row->scenario_id,
                'key' => (string) $row->scenario_key,
                'name' => (string) $row->scenario_name,
                'principal_mode' => (string) $row->principal_mode,
                'execution_user_id' => $row->execution_user_id !== null
                    ? (int) $row->execution_user_id
                    : null,
                'published_version' => (int) $row->published_version_id,
            ],
            $this->automationRepository->findActiveScenariosForTrigger($spaceId, $triggerId),
        );

        return [
            'trigger' => $this->presentTriggerDefinition($definition),
            'scenarios' => $scenarios,
        ];
    }

    /**
     * Génère ou renouvelle le secret d'un webhook.
     *
     * Le secret n'est retourné qu'une seule fois ; il n'est jamais relu par
     * `getScenario`. Le mode `query` renvoie aussi le chemin complet signé.
     *
     * @return array{mode: string, secret: string, path: string}
     */
    public function generateWebhookSecret(int $scenarioId, string $mode): array
    {
        $scenario = $this->requireScenario($scenarioId);
        $spaceId = (int) $scenario->space_id;
        $this->assertPermission(Permissions::PUBLISH, $spaceId);

        $trigger = $this->automationRepository->findTrigger($scenarioId);

        if ($trigger === null || (string) $trigger->trigger_id !== WebhookReceiver::TRIGGER_ID) {
            ExceptionResponse::throwWarning('Ce scénario n’a pas de déclencheur webhook.');
        }

        if (!WebhookAuthMode::isValid($mode)) {
            ExceptionResponse::throwWarning('Mode d’authentification webhook invalide.');
        }

        /** @var WebhookSecrets $secrets */
        $secrets = $this->getSharedExtensionsClass(WebhookSecrets::class);
        $secret = $mode === WebhookAuthMode::HMAC
            ? $secrets->generateHmacSecret($scenarioId)
            : $secrets->generateToken($scenarioId, $mode);
        $path = WebhookReceiver::PATH_PREFIX . $scenarioId;

        if ($mode === WebhookAuthMode::QUERY) {
            $path .= '?' . WebhookConfig::TOKEN_FIELD . '=' . rawurlencode($secret);
        }

        $this->logInfo(
            'Secret webhook généré pour le scénario #' . $scenarioId . ' (mode ' . $mode . ').',
            ['space_id' => $spaceId],
            'AUTOMATION_WEBHOOK_SECRET_GENERATED',
            'automation',
        );

        return [
            'mode' => $mode,
            'secret' => $secret,
            'path' => $path,
        ];
    }

    /** @return array<string, mixed> */
    public function createScenario(object $data): array
    {
        $spaceId = is_int($data->space_id ?? null) ? $data->space_id : 0;

        if ($spaceId <= 0) {
            ExceptionResponse::throwWarning('Espace invalide.');
        }

        $this->requireSpace($spaceId);
        $this->assertPermission(Permissions::EDIT, $spaceId);
        $key = $this->validateKey($data);

        if ($this->automationRepository->findScenarioByKey($spaceId, $key) !== null) {
            ExceptionResponse::throwWarning('Cette clé est déjà utilisée dans cet espace.');
        }

        [$name, $description, $principalMode, $triggerId, $source] = $this->validateMetadata($data);

        if (in_array($triggerId, [WebhookReceiver::TRIGGER_ID, self::SCHEDULE_TRIGGER], true)
            && $principalMode !== AutomationPrincipalMode::AUTHOR) {
            ExceptionResponse::throwWarning('Ce déclencheur exige le mode d’exécution « Auteur ».');
        }

        $resolvedTrigger = $this->resolveTriggerConfiguration(
            $triggerId,
            $data->trigger_config ?? null,
            null,
            $data->trigger_enabled ?? null,
        );
        $userId = $this->currentUserId();
        $compiled = $this->compileSource($source);

        $scenarioId = $this->automationRepository->createScenario(
            $spaceId,
            $key,
            $name,
            $description,
            $principalMode,
            null,
            $userId,
        );

        $this->automationRepository->upsertTrigger(
            $scenarioId,
            $spaceId,
            $triggerId,
            'main',
            json_encode($resolvedTrigger['config'], JSON_THROW_ON_ERROR),
            $resolvedTrigger['secret_hash'],
            $resolvedTrigger['enabled'],
        );
        $this->saveDraftVersion($scenarioId, $source, $compiled, $userId);

        return $this->getScenario($scenarioId);
    }

    /** @return array<string, mixed> */
    public function saveScenario(int $scenarioId, object $data): array
    {
        $scenario = $this->requireScenario($scenarioId);
        $spaceId = (int) $scenario->space_id;
        $this->assertPermission(Permissions::EDIT, $spaceId);
        [$name, $description, $principalMode, $triggerId, $source] = $this->validateMetadata($data);
        $userId = $this->currentUserId();

        // Le mode et le déclencheur changent immédiatement l'exécution du
        // scénario publié : un éditeur sans PUBLISH ne peut pas les modifier.
        $trigger = $this->automationRepository->findTrigger($scenarioId);

        if (in_array($triggerId, [WebhookReceiver::TRIGGER_ID, self::SCHEDULE_TRIGGER], true)
            && $principalMode !== AutomationPrincipalMode::AUTHOR) {
            ExceptionResponse::throwWarning('Ce déclencheur exige le mode d’exécution « Auteur ».');
        }

        $resolvedTrigger = $this->resolveTriggerConfiguration(
            $triggerId,
            $data->trigger_config ?? null,
            $trigger,
            $data->trigger_enabled ?? null,
        );
        $configChanged = $trigger === null
            || $this->decodeTriggerConfig((string) ($trigger->config ?? '')) !== $resolvedTrigger['config']
            || ((int) $trigger->enabled === 1) !== $resolvedTrigger['enabled'];
        $executionChanged = (string) $scenario->principal_mode !== $principalMode
            || ($trigger !== null && (string) $trigger->trigger_id !== $triggerId)
            || $configChanged;

        if ($scenario->published_version_id !== null
            && $executionChanged
            && !$this->can(Permissions::PUBLISH, $spaceId)) {
            ExceptionResponse::throwDanger(
                'La modification du mode d’exécution ou du déclencheur d’un scénario publié requiert la permission PUBLISH.',
            );
        }

        // Le mode author conserve l'auteur déjà publié jusqu'à la prochaine
        // publication, qui fixera le publieur courant.
        $executionUserId = $principalMode === AutomationPrincipalMode::AUTHOR
            ? ($scenario->execution_user_id !== null ? (int) $scenario->execution_user_id : null)
            : null;

        $this->automationRepository->updateScenario(
            $scenarioId,
            $name,
            $description,
            $principalMode,
            $executionUserId,
        );
        $this->automationRepository->upsertTrigger(
            $scenarioId,
            $spaceId,
            $triggerId,
            'main',
            json_encode($resolvedTrigger['config'], JSON_THROW_ON_ERROR),
            $resolvedTrigger['secret_hash'],
            $resolvedTrigger['enabled'],
        );

        $draft = $scenario->draft_version_id !== null
            ? $this->automationRepository->findVersion((int) $scenario->draft_version_id)
            : null;

        // Une sauvegarde de métadonnées seules ne crée pas de version inutile.
        if ($draft === null || (string) $draft->source_hash !== hash('sha256', $source)) {
            $compiled = $this->compileSource($source);
            $this->saveDraftVersion($scenarioId, $source, $compiled, $userId);
        }

        return $this->getScenario($scenarioId);
    }

    /** @return array<string, mixed> */
    public function publishScenario(int $scenarioId): array
    {
        $scenario = $this->requireScenario($scenarioId);
        $spaceId = (int) $scenario->space_id;
        $this->assertPermission(Permissions::PUBLISH, $spaceId);

        $trigger = $this->automationRepository->findTrigger($scenarioId);

        if ($trigger === null) {
            ExceptionResponse::throwWarning('Le scénario n’a pas de déclencheur.');
        }

        $this->assertTriggerAvailable((string) $trigger->trigger_id);

        $draft = $scenario->draft_version_id !== null
            ? $this->automationRepository->findVersion((int) $scenario->draft_version_id)
            : null;

        if ($draft === null) {
            ExceptionResponse::throwWarning('Aucune version brouillon à publier.');
        }

        $compiled = $this->compileSource((string) $draft->source);

        if (!$compiled->isSuccessful() || $compiled->program === null) {
            return [
                'published' => false,
                'diagnostics' => $this->presentDiagnostics($compiled->diagnostics),
                'scenario' => $this->getScenario($scenarioId),
            ];
        }

        $publisherId = $this->currentUserId();
        $principalMode = (string) $scenario->principal_mode;
        $this->assertPublishable($compiled->program, $spaceId, $publisherId, $principalMode);

        // On ne publie jamais au nom d'un autre utilisateur.
        $this->automationRepository->setExecutionUser(
            $scenarioId,
            $principalMode === AutomationPrincipalMode::AUTHOR ? $publisherId : null,
        );
        $this->automationRepository->publishVersion($scenarioId, (int) $draft->id, $publisherId);

        return [
            'published' => true,
            'diagnostics' => [],
            'scenario' => $this->getScenario($scenarioId),
        ];
    }

    /** @return array<string, mixed> */
    public function archiveScenario(int $scenarioId): array
    {
        $scenario = $this->requireScenario($scenarioId);
        $this->assertPermission(Permissions::PUBLISH, (int) $scenario->space_id);
        $this->automationRepository->archiveScenario($scenarioId);

        return ['archived' => true];
    }

    /** @return array<string, mixed> */
    public function listRuns(int $scenarioId, int $page, int $perPage): array
    {
        $scenario = $this->requireScenario($scenarioId);
        $this->assertCanSee((int) $scenario->space_id);
        $page = max(1, min(100000, $page));
        $perPage = in_array($perPage, [10, 20, 50], true) ? $perPage : 20;
        $total = $this->automationRepository->countRuns($scenarioId);

        return [
            'runs' => array_map(
                fn (object $row): array => $this->presentRunRow($row),
                $this->automationRepository->listRuns($scenarioId, ($page - 1) * $perPage, $perPage),
            ),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
        ];
    }

    /** @return array<string, mixed> */
    public function getRun(int $runId): array
    {
        $run = $this->automationRepository->findRunById($runId);

        if ($run === null) {
            ExceptionResponse::throwWarning('Run introuvable.');
        }

        $this->assertCanSee((int) $run->scenario_space_id);

        return [
            'run' => $this->presentRunDetail($run),
            'steps' => array_map(
                fn (object $row): array => $this->presentStep($row),
                $this->automationRepository->listRunSteps($runId),
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function getMaintenance(int $spaceId): array
    {
        $this->requireSpace($spaceId);
        $this->assertPermission(Permissions::VIEW, $spaceId);
        $retention = $this->maintenanceRetention($spaceId);
        $events = $this->automationRepository->findStuckEvents(
            $spaceId,
            MaintenancePolicy::PENDING_EVENT_SECONDS,
            MaintenancePolicy::PROCESSING_EVENT_SECONDS,
            MaintenancePolicy::LIST_LIMIT,
        );
        $runs = $this->automationRepository->findStuckRuns(
            $spaceId,
            MaintenancePolicy::STUCK_RUN_SECONDS,
            MaintenancePolicy::LIST_LIMIT,
        );
        $failedRuns = $this->automationRepository->findRecentFailedRuns(
            $spaceId,
            MaintenancePolicy::LIST_LIMIT,
        );

        return [
            'space_id' => $spaceId,
            'can_manage' => $this->can(Permissions::RUN_MANAGE, $spaceId),
            'can_run' => $this->can(Permissions::RUN, $spaceId),
            'thresholds' => [
                'pending_event_minutes' => intdiv(MaintenancePolicy::PENDING_EVENT_SECONDS, 60),
                'processing_event_minutes' => intdiv(MaintenancePolicy::PROCESSING_EVENT_SECONDS, 60),
                'stuck_run_minutes' => intdiv(MaintenancePolicy::STUCK_RUN_SECONDS, 60),
                'max_event_attempts' => MaintenancePolicy::MAX_EVENT_ATTEMPTS,
            ],
            'retention' => $retention,
            'stuck_events' => array_map(
                static fn (object $row): array => [
                    'id' => (int) $row->id,
                    'trigger_id' => (string) $row->trigger_id,
                    'target_scenario_key' => $row->target_scenario_key !== null
                        ? (string) $row->target_scenario_key
                        : null,
                    'status' => (string) $row->status,
                    'attempts' => (int) $row->attempts,
                    'occurred_at' => $row->occurred_at,
                    'last_error' => $row->last_error,
                ],
                $events,
            ),
            'stuck_runs' => array_map(
                static fn (object $row): array => [
                    'id' => (int) $row->id,
                    'scenario_id' => (int) $row->scenario_id,
                    'scenario_name' => (string) $row->scenario_name,
                    'scenario_key' => (string) $row->scenario_key,
                    'trigger_id' => $row->trigger_id !== null ? (string) $row->trigger_id : null,
                    'status' => (string) $row->status,
                    'queued_at' => $row->queued_at,
                    'started_at' => $row->started_at,
                ],
                $runs,
            ),
            'failed_runs' => array_map(
                static fn (object $row): array => [
                    'id' => (int) $row->id,
                    'scenario_id' => (int) $row->scenario_id,
                    'scenario_name' => (string) $row->scenario_name,
                    'scenario_key' => (string) $row->scenario_key,
                    'trigger_id' => $row->trigger_id !== null ? (string) $row->trigger_id : null,
                    'status' => (string) $row->status,
                    'created' => $row->created,
                    'finished_at' => $row->finished_at,
                    'error_code' => $row->error_code,
                    'error_message' => $row->error_message,
                ],
                $failedRuns,
            ),
        ];
    }

    /** @return array<string, mixed> */
    public function recoverEvent(int $eventId): array
    {
        $event = $this->automationRepository->findEventById($eventId);

        if ($event === null) {
            ExceptionResponse::throwWarning('Événement introuvable.');
        }

        $spaceId = (int) $event->space_id;
        $this->assertPermission(Permissions::RUN_MANAGE, $spaceId);
        $status = (string) $event->status;

        if (!in_array($status, ['pending', 'processing'], true)) {
            ExceptionResponse::throwWarning('Cet événement est déjà terminé.');
        }

        if ((int) $event->attempts >= MaintenancePolicy::MAX_EVENT_ATTEMPTS) {
            ExceptionResponse::throwWarning(
                'Cet événement a atteint le nombre maximal de tentatives ; marquez-le en échec.',
            );
        }

        if ($status === 'processing') {
            $recovered = $this->automationRepository->recoverStuckEvent(
                $eventId,
                MaintenancePolicy::PROCESSING_EVENT_SECONDS,
                MaintenancePolicy::MAX_EVENT_ATTEMPTS,
            );

            if (!$recovered) {
                ExceptionResponse::throwWarning(
                    'Cet événement a déjà été repris ou n’est pas encore assez ancien.',
                );
            }
        } elseif (time() - strtotime((string) $event->occurred_at) < MaintenancePolicy::PENDING_EVENT_SECONDS) {
            ExceptionResponse::throwWarning('Cet événement est encore récent ; attendez avant de le reprendre.');
        }

        try {
            $results = $this->automationEventDispatcher->dispatch((string) $event->event_id);
        } catch (\Throwable $exception) {
            $this->logError(
                'La reprise manuelle d’un événement a échoué.',
                ['space_id' => $spaceId, 'event_id' => $eventId],
                'AUTOMATION_MAINTENANCE_EVENT_FAILED',
                'automation',
            );
            $this->logException($exception);

            ExceptionResponse::throwWarning('La reprise de l’événement a échoué.');
        }

        $fresh = $this->automationRepository->findEventById($eventId);

        // Un second clic concurrent peut perdre la réservation atomique :
        // l'événement est alors encore processing et aucun run ne lui appartient.
        if ($results === [] && $fresh !== null && (string) $fresh->status === 'processing') {
            ExceptionResponse::throwWarning('Cet événement est déjà en cours de reprise.');
        }

        $this->logInfo(
            'Événement repris depuis la maintenance.',
            ['space_id' => $spaceId, 'event_id' => $eventId, 'runs' => count($results)],
            'AUTOMATION_MAINTENANCE_EVENT_RECOVERED',
            'automation',
        );

        return [
            'recovered' => true,
            'event_id' => $eventId,
            'status' => $fresh !== null ? (string) $fresh->status : 'unknown',
            'runs' => count($results),
        ];
    }

    /** @return array<string, mixed> */
    public function failEvent(int $eventId): array
    {
        $event = $this->automationRepository->findEventById($eventId);

        if ($event === null) {
            ExceptionResponse::throwWarning('Événement introuvable.');
        }

        $spaceId = (int) $event->space_id;
        $this->assertPermission(Permissions::RUN_MANAGE, $spaceId);

        if (!in_array((string) $event->status, ['pending', 'processing'], true)) {
            ExceptionResponse::throwWarning('Cet événement est déjà terminé.');
        }

        $this->automationRepository->markEventFailed(
            $eventId,
            'Marqué en échec depuis la maintenance.',
        );
        $this->logInfo(
            'Événement marqué en échec depuis la maintenance.',
            ['space_id' => $spaceId, 'event_id' => $eventId],
            'AUTOMATION_MAINTENANCE_EVENT_FAILED',
            'automation',
        );

        return ['failed' => true, 'event_id' => $eventId];
    }

    /** @return array<string, mixed> */
    public function failStuckRun(int $runId): array
    {
        $run = $this->automationRepository->findRunById($runId);

        if ($run === null) {
            ExceptionResponse::throwWarning('Run introuvable.');
        }

        $spaceId = (int) $run->scenario_space_id;
        $this->assertPermission(Permissions::RUN_MANAGE, $spaceId);

        if (!in_array((string) $run->status, ['queued', 'running'], true)) {
            ExceptionResponse::throwWarning('Ce run est déjà terminé.');
        }

        if (!$this->automationRepository->markStuckRunFailed($runId, MaintenancePolicy::STUCK_RUN_SECONDS)) {
            ExceptionResponse::throwWarning(
                'Ce run a déjà été traité ou n’est pas encore assez ancien.',
            );
        }

        $this->automationFailureNotifier->notify(
            $spaceId,
            $runId,
            (string) $run->scenario_name,
            (string) ($run->trigger_id ?? 'manual'),
            'AUTOMATION_RUN_STUCK',
            'Run interrompu : aucun worker ne l’a terminé.',
        );
        $this->logInfo(
            'Run bloqué marqué en échec depuis la maintenance.',
            ['space_id' => $spaceId, 'run_id' => $runId],
            'AUTOMATION_MAINTENANCE_RUN_FAILED',
            'automation',
        );

        return ['failed' => true, 'run_id' => $runId];
    }

    /** @return array<string, mixed> */
    public function cleanupMaintenance(int $spaceId): array
    {
        $this->requireSpace($spaceId);
        $this->assertPermission(Permissions::RUN_MANAGE, $spaceId);
        $retention = $this->maintenanceRetention($spaceId);
        $eventsDeleted = 0;
        $runsDeleted = 0;

        for ($batch = 0; $batch < MaintenancePolicy::CLEANUP_MAX_BATCHES; $batch++) {
            $deleted = $this->automationRepository->deleteOldEvents(
                $spaceId,
                $retention['event_days'],
                MaintenancePolicy::CLEANUP_BATCH_SIZE,
            );
            $eventsDeleted += $deleted;

            if ($deleted < MaintenancePolicy::CLEANUP_BATCH_SIZE) {
                break;
            }
        }

        for ($batch = 0; $batch < MaintenancePolicy::CLEANUP_MAX_BATCHES; $batch++) {
            $deleted = $this->automationRepository->deleteOldRuns(
                $spaceId,
                $retention['run_days'],
                MaintenancePolicy::CLEANUP_BATCH_SIZE,
            );
            $runsDeleted += $deleted;

            if ($deleted < MaintenancePolicy::CLEANUP_BATCH_SIZE) {
                break;
            }
        }

        $this->logInfo(
            'Nettoyage manuel de la maintenance terminé.',
            [
                'space_id' => $spaceId,
                'events_deleted' => $eventsDeleted,
                'runs_deleted' => $runsDeleted,
                'event_days' => $retention['event_days'],
                'run_days' => $retention['run_days'],
            ],
            'AUTOMATION_MAINTENANCE_CLEANUP',
            'automation',
        );

        return [
            'events_deleted' => $eventsDeleted,
            'runs_deleted' => $runsDeleted,
            'retention' => $retention,
        ];
    }

    /** @return array{event_days: int, run_days: int, event_purgeable: int, run_purgeable: int} */
    private function maintenanceRetention(int $spaceId): array
    {
        $row = $this->db->selectOneOrNull(
            'SELECT params FROM config WHERE space_id = % AND extension = % LIMIT 1',
            [$spaceId, 'plg_automation'],
        );
        $config = [];

        if ($row !== null) {
            $decoded = json_decode((string) $row->params, true);
            $config = is_array($decoded) ? $decoded : [];
        }

        $eventDays = $this->clampRetention(
            $config['event_retention_days'] ?? null,
            MaintenancePolicy::DEFAULT_EVENT_RETENTION_DAYS,
        );
        $runDays = $this->clampRetention(
            $config['run_retention_days'] ?? null,
            MaintenancePolicy::DEFAULT_RUN_RETENTION_DAYS,
        );

        return [
            'event_days' => $eventDays,
            'run_days' => $runDays,
            'event_purgeable' => $this->automationRepository->countPurgeableEvents($spaceId, $eventDays),
            'run_purgeable' => $this->automationRepository->countPurgeableRuns($spaceId, $runDays),
        ];
    }

    private function clampRetention(mixed $value, int $default): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }

        if (!is_int($value)) {
            return $default;
        }

        return max(1, min(MaintenancePolicy::MAX_RETENTION_DAYS, $value));
    }

    /** Référence Markdown téléchargeable du langage et des actions. */
    public function referenceDocument(): string
    {
        if ($this->coreAclHelper->getSpacesHavingGlobalPermission(Permissions::VIEW) === []) {
            ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour consulter la référence.');
        }

        return AutomationReferenceDocument::markdown(
            $this->builtinReference(),
            $this->presentActionDefinitions(),
            ContextVariableCatalog::base(),
            $this->presentTriggerDefinitions(),
            BuiltinStatementReference::all(),
        );
    }

    /**
     * Simule la source courante sans écrire de run.
     *
     * Les actions de lecture restent permises et vérifient l'ACL ; les écritures
     * et les effets externes sont refusés par le répartiteur de simulation.
     *
     * @return array<string, mixed>
     */
    public function dryRun(object $data): array
    {
        $scenarioId = is_int($data->scenario_id ?? null) ? max(0, $data->scenario_id) : 0;
        $source = is_string($data->source ?? null) ? $data->source : '';

        if (strlen($source) > self::MAX_SOURCE_BYTES) {
            ExceptionResponse::throwWarning('La source dépasse la taille maximale.');
        }

        if ($scenarioId > 0) {
            $scenario = $this->requireScenario($scenarioId);
            $spaceId = (int) $scenario->space_id;
            $this->assertPermission(Permissions::EDIT, $spaceId);
        } else {
            $spaceId = is_int($data->space_id ?? null) ? $data->space_id : 0;

            if ($spaceId <= 0) {
                ExceptionResponse::throwWarning('Espace invalide.');
            }

            $this->requireSpace($spaceId);
            $this->assertPermission(Permissions::EDIT, $spaceId);
        }

        $input = $this->safeInput($this->decodeInput($data->input ?? []));
        $catalog = $this->functionCatalog();
        $compiled = $this->compileSource($source, $catalog);

        if (!$compiled->isSuccessful() || $compiled->program === null) {
            return [
                'status' => 'invalid',
                'output' => null,
                'message' => 'La source contient des erreurs.',
                'diagnostics' => $this->presentDiagnostics($compiled->diagnostics),
                'steps' => 0,
                'action_calls' => 0,
                'duration_ms' => 0,
            ];
        }

        $registry = $this->actionRegistry();
        $dispatcher = new DryRunActionDispatcher(
            new RegistryActionDispatcher($this->app, $registry),
            $registry,
        );
        $userId = $this->currentUserId();
        $context = ExecutionContext::create(
            $input,
            $this->automationRunExecutor->principalFor($userId),
            $this->automationRunExecutor->spaceFor($spaceId),
            [
                'id' => 0,
                'scenarioId' => $scenarioId,
                'versionId' => 0,
                'trigger' => 'dry-run',
            ],
            $dispatcher,
        );
        $result = (new Interpreter($catalog, new BuiltinFunctionRegistry(), new Limits()))
            ->execute($compiled->program, $context);

        return [
            'status' => $result->status->value,
            'output' => $this->safeOutput($result->output),
            'message' => $result->message,
            'diagnostics' => $this->presentDiagnostics($result->diagnostics),
            'steps' => $result->steps,
            'action_calls' => $result->actionCalls,
            'duration_ms' => (int) round($result->durationSeconds * 1000),
        ];
    }

    private function validateKey(object $data): string
    {
        $key = is_string($data->key ?? null) ? strtolower(trim($data->key)) : '';

        if ($key === '' || strlen($key) > 64 || preg_match('/\A[a-z][a-z0-9_-]*\z/', $key) !== 1) {
            ExceptionResponse::throwWarning(
                'La clé doit commencer par une lettre et ne contenir que des minuscules, chiffres, tirets ou underscores.',
            );
        }

        return $key;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string, 4: string}
     */
    private function validateMetadata(object $data): array
    {
        $name = is_string($data->name ?? null) ? trim($data->name) : '';

        if ($name === '' || mb_strlen($name) > 150) {
            ExceptionResponse::throwWarning('Le nom du scénario est invalide.');
        }

        $description = is_string($data->description ?? null) ? trim($data->description) : '';

        if (mb_strlen($description) > 2000) {
            ExceptionResponse::throwWarning('La description du scénario est trop longue.');
        }

        $principalMode = is_string($data->principal_mode ?? null) ? $data->principal_mode : '';

        if (!in_array($principalMode, [
            AutomationPrincipalMode::TRIGGER,
            AutomationPrincipalMode::AUTHOR,
        ], true)) {
            ExceptionResponse::throwWarning('Le mode d’exécution du scénario est invalide.');
        }

        $triggerId = is_string($data->trigger_id ?? null) ? trim($data->trigger_id) : '';

        if ($triggerId === '' || mb_strlen($triggerId) > 100) {
            ExceptionResponse::throwWarning('Le déclencheur du scénario est invalide.');
        }

        $this->assertTriggerAvailable($triggerId);

        $source = is_string($data->source ?? null) ? $data->source : '';

        if (strlen($source) > self::MAX_SOURCE_BYTES) {
            ExceptionResponse::throwWarning('La source dépasse la taille maximale.');
        }

        return [$name, $description, $principalMode, $triggerId, $source];
    }

    private function saveDraftVersion(
        int $scenarioId,
        string $source,
        CompilationResult $compiled,
        int $userId,
    ): int {
        $versionNumber = $this->automationRepository->nextVersionNumber($scenarioId);
        $diagnostics = json_encode(
            $this->presentDiagnostics($compiled->diagnostics),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        );

        $versionId = $this->automationRepository->createVersion(
            $scenarioId,
            $versionNumber,
            $compiled->isSuccessful() ? 'ok' : 'invalid',
            $source,
            hash('sha256', $source),
            $this->astJson($compiled->program),
            1,
            1,
            $diagnostics,
            $userId,
        );
        $this->automationRepository->setDraftVersion($scenarioId, $versionId);

        return $versionId;
    }

    private function compileSource(
        string $source,
        ?AutomationFunctionCatalog $catalog = null,
    ): CompilationResult {
        $catalog ??= $this->functionCatalog();

        return (new ScenarioCompiler($catalog, new Limits()))->compile($source);
    }

    private function functionCatalog(): AutomationFunctionCatalog
    {
        return new AutomationFunctionCatalog(
            BuiltinFunctionCatalog::create(),
            $this->actionRegistry(),
        );
    }

    private function assertPublishable(
        Program $program,
        int $spaceId,
        int $publisherId,
        string $principalMode,
    ): void {
        $registry = $this->actionRegistry();
        $collector = new ActionReferenceCollector($registry);

        foreach ($collector->collect($program) as $actionId) {
            $definition = $registry->find($actionId);

            if ($definition === null) {
                continue;
            }

            foreach ($definition->requiredPermissions as $permission) {
                if (!$this->coreAclHelper->userHasGlobalPermission($publisherId, $permission, $spaceId)) {
                    ExceptionResponse::throwDanger(
                        'Vous ne pouvez pas publier ce scénario : l’action « ' . $definition->label
                        . ' » exige la permission « ' . $permission . ' ».',
                    );
                }
            }
        }

        if ($principalMode === AutomationPrincipalMode::AUTHOR
            && !$this->coreAclHelper->hasGlobalPermission(Permissions::RUN_AS_AUTHOR, $spaceId)) {
            ExceptionResponse::throwDanger(
                'La permission RUN_AS_AUTHOR est requise pour publier un scénario exécuté avec vos droits.',
            );
        }
    }

    private function assertTriggerAvailable(string $triggerId): void
    {
        $registry = $this->triggerRegistry();

        if ($registry === null || !$registry->has($triggerId)) {
            ExceptionResponse::throwWarning('Le déclencheur sélectionné n’est pas disponible.');
        }
    }

    /** @return list<array<string, mixed>> */
    private function presentTriggerDefinitions(): array
    {
        $registry = $this->triggerRegistry();

        if ($registry === null) {
            return [];
        }

        $result = [];

        foreach ($registry->all() as $definition) {
            $result[] = $this->presentTriggerDefinition($definition);
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function presentTriggerDefinition(AutomationTriggerDefinition $definition): array
    {
        return [
            'id' => $definition->id,
            'label' => $definition->label,
            'description' => $definition->description,
            'default_principal_mode' => $definition->defaultPrincipalMode,
            'payload_fields' => array_map(
                static fn (AutomationPayloadFieldDefinition $field): array => [
                    'name' => $field->name,
                    'type' => $field->type,
                    'required' => $field->required,
                    'description' => $field->description,
                ],
                $definition->payloadFields,
            ),
            'payload_example' => $definition->payloadExample,
        ];
    }

    /** @return list<array{namespace: string, id: string, signature: string, description: string, effect: string}> */
    private function builtinReference(): array
    {
        $catalog = BuiltinFunctionCatalog::create();
        $result = [];

        foreach (BuiltinFunctionReference::all() as $builtin) {
            $signature = $catalog->find($builtin['id']);
            $result[] = $builtin + ['effect' => $signature?->effect ?? FunctionEffect::PURE];
        }

        return $result;
    }

    /** @return list<array<string, mixed>> */
    private function presentActionDefinitions(): array
    {
        $registry = $this->automationPlugin()?->getActionRegistry();

        if ($registry === null) {
            return [];
        }

        $result = [];

        foreach ($registry->all() as $definition) {
            $result[] = [
                'id' => $definition->id,
                'label' => $definition->label,
                'description' => $definition->description,
                'return_type' => $definition->returnType,
                'effect' => $definition->effect,
                'required_permissions' => $definition->requiredPermissions,
                'parameters' => array_map(
                    static fn (AutomationParameterDefinition $parameter): array => [
                        'name' => $parameter->name,
                        'type' => $parameter->type,
                        'required' => $parameter->required,
                        'default' => $parameter->default,
                        'label' => $parameter->label,
                        'description' => $parameter->description,
                    ],
                    $definition->parameters,
                ),
            ];
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function presentScenarioRow(object $row): array
    {
        $spaceId = (int) $row->space_id;

        return [
            'id' => (int) $row->id,
            'space_id' => $spaceId,
            'space_title' => (string) ($row->space_title ?? ''),
            'key' => (string) $row->key,
            'name' => (string) $row->name,
            'status' => (string) $row->status,
            'principal_mode' => (string) $row->principal_mode,
            'trigger_id' => isset($row->trigger_id) ? (string) $row->trigger_id : null,
            'draft_version' => isset($row->draft_version) ? (int) $row->draft_version : null,
            'published_version' => isset($row->published_version) ? (int) $row->published_version : null,
            'last_run_status' => isset($row->last_run_status) ? (string) $row->last_run_status : null,
            'last_run_at' => isset($row->last_run_at) ? (string) $row->last_run_at : null,
            'can_edit' => $this->can(Permissions::EDIT, $spaceId),
            'can_publish' => $this->can(Permissions::PUBLISH, $spaceId),
            'can_run' => $this->can(Permissions::RUN, $spaceId) && $row->published_version_id !== null,
            'can_archive' => $this->can(Permissions::PUBLISH, $spaceId),
        ];
    }

    /** @return array<string, mixed> */
    private function presentRunRow(object $row): array
    {
        return [
            'id' => (int) $row->id,
            'status' => (string) $row->status,
            'trigger_id' => $row->trigger_id !== null ? (string) $row->trigger_id : null,
            'principal_type' => (string) $row->principal_type,
            'principal_id' => $row->principal_id !== null ? (int) $row->principal_id : null,
            'principal_label' => $row->principal_label !== null ? (string) $row->principal_label : null,
            'started_at' => $row->started_at,
            'finished_at' => $row->finished_at,
            'duration_ms' => $row->duration_ms !== null ? (int) $row->duration_ms : null,
            'steps_count' => (int) $row->steps_count,
            'action_calls' => (int) $row->action_calls,
            'error_code' => $row->error_code,
            'error_message' => $row->error_message,
            'created' => $row->created,
        ];
    }

    /** @return array<string, mixed> */
    private function presentRunDetail(object $row): array
    {
        return $this->presentRunRow($row) + [
            'scenario_id' => (int) $row->scenario_id,
            'version_id' => (int) $row->version_id,
            'dedup_key' => $row->dedup_key,
            'depth' => (int) $row->depth,
            'loop_iterations' => (int) $row->loop_iterations,
            'input' => $row->input,
            'output' => $row->output,
            'message' => $row->message,
        ];
    }

    /** @return array<string, mixed> */
    private function presentStep(object $row): array
    {
        return [
            'sequence' => (int) $row->sequence,
            'kind' => (string) $row->kind,
            'identifier' => (string) $row->identifier,
            'status' => (string) $row->status,
            'duration_ms' => $row->duration_ms !== null ? (int) $row->duration_ms : null,
            'error_code' => $row->error_code,
            'error_message' => $row->error_message,
            'span_start_line' => $row->span_start_line !== null ? (int) $row->span_start_line : null,
            'span_start_column' => $row->span_start_column !== null ? (int) $row->span_start_column : null,
            'span_end_line' => $row->span_end_line !== null ? (int) $row->span_end_line : null,
            'span_end_column' => $row->span_end_column !== null ? (int) $row->span_end_column : null,
        ];
    }

    /** @return array<string, mixed> */
    private function presentTrigger(object $trigger, object $scenario, ?object $space): array
    {
        $triggerId = (string) $trigger->trigger_id;
        $config = $this->decodeTriggerConfig((string) ($trigger->config ?? ''));
        $presented = [
            'id' => $triggerId,
            'enabled' => (int) $trigger->enabled === 1,
            'config' => [],
        ];

        if ($triggerId === WebhookReceiver::TRIGGER_ID) {
            $mode = WebhookConfig::authMode($config);

            return array_replace($presented, [
                'config' => [
                    'auth_mode' => $mode,
                    'response_mode' => WebhookConfig::responseMode($config),
                ],
                'auth_mode' => $mode,
                'webhook_url' => WebhookReceiver::PATH_PREFIX . (int) $scenario->id,
                'secret_configured' => WebhookConfig::secretConfigured($trigger, $config),
            ]);
        }

        if ($triggerId === self::SCHEDULE_TRIGGER) {
            $timezone = $this->spaceTimezone($space);

            try {
                $config = ScheduleConfig::normalize($config);
                $nextRun = ScheduleCalculator::nextRun($config, $timezone);
            } catch (\Throwable) {
                // Une configuration ancienne ou forgée ne doit pas casser la
                // lecture du scénario : on affiche les valeurs par défaut.
                $config = ScheduleConfig::normalize([]);
                $nextRun = ScheduleCalculator::nextRun($config, $timezone);
            }

            return array_replace($presented, [
                'config' => $config,
                'timezone' => $timezone,
                'next_run' => $nextRun->format(DATE_ATOM),
            ]);
        }

        return array_replace($presented, ['config' => $config]);
    }

    private function spaceTimezone(?object $space): string
    {
        return ScheduleCalculator::spaceTimezone($space->params ?? null);
    }

    /** @return array<string, mixed> */
    private function decodeTriggerConfig(string $json): array
    {
        if ($json === '') {
            return [];
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) && !array_is_list($decoded) ? $decoded : [];
    }

    /**
     * Fusionne la configuration du trigger en préservant les secrets.
     *
     * @return array{config: array<string, mixed>, secret_hash: ?string, enabled: bool}
     */
    private function resolveTriggerConfiguration(
        string $triggerId,
        mixed $input,
        ?object $existingTrigger,
        mixed $enabledInput,
    ): array {
        $sameTrigger = $existingTrigger !== null
            && (string) $existingTrigger->trigger_id === $triggerId;
        $existingConfig = $sameTrigger
            ? $this->decodeTriggerConfig((string) ($existingTrigger->config ?? ''))
            : [];

        if (is_bool($enabledInput)) {
            $enabled = $enabledInput;
        } elseif (is_string($enabledInput)) {
            $enabled = in_array($enabledInput, ['1', 'true'], true);
        } else {
            $enabled = $sameTrigger ? (int) $existingTrigger->enabled === 1 : true;
        }

        if ($triggerId === WebhookReceiver::TRIGGER_ID) {
            $modeInput = is_object($input)
                ? ($input->auth_mode ?? null)
                : (is_array($input) ? ($input['auth_mode'] ?? null) : null);

            if ($modeInput !== null
                && (!is_string($modeInput) || !WebhookAuthMode::isValid($modeInput))) {
                ExceptionResponse::throwWarning('Mode d’authentification webhook invalide.');
            }

            $config = WebhookConfig::merge($input, $existingConfig);
            $newMode = WebhookConfig::authMode($config);
            $oldMode = $sameTrigger ? WebhookConfig::authMode($existingConfig) : $newMode;
            $secretHash = $sameTrigger && $newMode === $oldMode
                ? ($existingTrigger->secret_hash ?? null)
                : null;

            if ($newMode === WebhookAuthMode::HMAC) {
                $secretHash = null;
            }

            return [
                'config' => $config,
                'secret_hash' => $secretHash,
                'enabled' => $enabled,
            ];
        }

        if ($triggerId === self::SCHEDULE_TRIGGER) {
            try {
                $config = ScheduleConfig::normalize($input, $existingConfig);
            } catch (\InvalidArgumentException $exception) {
                ExceptionResponse::throwWarning($exception->getMessage());
            }

            return [
                'config' => $config,
                'secret_hash' => null,
                'enabled' => $enabled,
            ];
        }

        return [
            'config' => $existingConfig,
            'secret_hash' => $sameTrigger ? ($existingTrigger->secret_hash ?? null) : null,
            'enabled' => $enabled,
        ];
    }

    /** @return array<string, mixed>|null */
    private function presentVersion(?object $version): ?array
    {
        if ($version === null) {
            return null;
        }

        $diagnostics = [];

        if (is_string($version->diagnostics ?? null) && $version->diagnostics !== '') {
            $decoded = json_decode($version->diagnostics, true);

            if (is_array($decoded)) {
                $diagnostics = $decoded;
            }
        }

        return [
            'id' => (int) $version->id,
            'version_number' => (int) $version->version_number,
            'status' => (string) $version->status,
            'compile_status' => (string) $version->compile_status,
            'diagnostics' => $diagnostics,
            'created' => $version->created,
            'published_at' => $version->published_at,
        ];
    }

    /** @return array<string, mixed>|null */
    private function presentUser(int $userId): ?array
    {
        $user = $this->coreUserHelper->getUser($userId, true);

        if ($user === null) {
            return null;
        }

        $label = trim($user->first_name . ' ' . $user->last_name);

        return [
            'id' => (int) $user->id,
            'label' => $label !== '' ? $label : (string) $user->email,
            'email' => (string) $user->email,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function presentDiagnostics(array $diagnostics): array
    {
        return array_map(static function (Diagnostic $diagnostic): array {
            return [
                'code' => $diagnostic->code,
                'message' => $diagnostic->message,
                'severity' => $diagnostic->severity->value,
                'line' => $diagnostic->span->start->line,
                'column' => $diagnostic->span->start->column,
                'end_line' => $diagnostic->span->end->line,
                'end_column' => $diagnostic->span->end->column,
            ];
        }, $diagnostics);
    }

    private function astJson(?Program $program): ?string
    {
        if ($program === null) {
            return null;
        }

        try {
            return json_encode(
                $program,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            );
        } catch (\Throwable $exception) {
            $this->logWarning(
                'L’instantané AST du scénario n’a pas pu être enregistré.',
                [],
                'AUTOMATION_AST_SNAPSHOT_FAILED',
                'automation',
            );
            $this->logException($exception);

            return null;
        }
    }

    private function safeOutput(mixed $output): mixed
    {
        return json_decode(
            AutomationValueJson::encode($output),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    /** @return array<mixed> */
    private function decodeInput(mixed $input): array
    {
        if ($input === null) {
            return [];
        }

        if (is_object($input)) {
            $input = json_decode(
                json_encode($input, JSON_THROW_ON_ERROR),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );
        }

        if (!is_array($input)) {
            ExceptionResponse::throwWarning('L’entrée de simulation doit être un objet JSON.');
        }

        if ($input !== [] && array_is_list($input)) {
            ExceptionResponse::throwWarning('L’entrée de simulation doit être une map JSON.');
        }

        return $input;
    }

    /** @return array<mixed> */
    private function safeInput(array $input): array
    {
        try {
            $safe = ValueTools::assertSafe($input, new Limits(), self::neutralSpan());
        } catch (\Throwable) {
            ExceptionResponse::throwWarning('L’entrée de simulation est invalide.');

            return [];
        }

        if (!is_array($safe)) {
            ExceptionResponse::throwWarning('L’entrée de simulation doit être une map JSON.');

            return [];
        }

        return $safe;
    }

    private function requireScenario(int $scenarioId): object
    {
        $scenario = $this->automationRepository->findScenarioById($scenarioId);

        if ($scenario === null) {
            ExceptionResponse::throwWarning('Scénario introuvable.');
        }

        return $scenario;
    }

    private function requireSpace(int $spaceId): object
    {
        $space = $this->coreSpaceHelper->getSpace($spaceId);

        if ($space === null) {
            ExceptionResponse::throwWarning('Espace introuvable.');
        }

        return $space;
    }

    private function currentUserId(): int
    {
        $userId = $this->session->getUserId();

        if ($userId === null || $userId <= 0) {
            ExceptionResponse::throwDanger('Vous devez être connecté.');
        }

        return $userId;
    }

    private function can(string $permission, int $spaceId): bool
    {
        return $this->coreAclHelper->hasGlobalPermission($permission, $spaceId);
    }

    /** Lecture du scénario et de son historique : VIEW, ou un droit d'édition. */
    private function assertCanSee(int $spaceId): void
    {
        foreach ([Permissions::VIEW, Permissions::EDIT, Permissions::PUBLISH] as $permission) {
            if ($this->can($permission, $spaceId)) {
                return;
            }
        }

        ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour consulter ce scénario.');
    }

    private function assertPermission(string $permission, int $spaceId): void
    {
        if (!$this->can($permission, $spaceId)) {
            ExceptionResponse::throwDanger('Vous ne disposez pas des droits nécessaires pour cette action.');
        }
    }

    private function actionRegistry(): AutomationActionRegistry
    {
        $registry = $this->automationPlugin()?->getActionRegistry();

        if ($registry === null) {
            throw new \RuntimeException('Le registre d’actions d’automatisation est indisponible.');
        }

        return $registry;
    }

    private function triggerRegistry(): ?AutomationTriggerRegistry
    {
        return $this->automationPlugin()?->getTriggerRegistry();
    }

    private function automationPlugin(): ?Plugin
    {
        $plugin = $this->app->getExtensions()['plg_automation'] ?? null;

        return $plugin instanceof Plugin ? $plugin : null;
    }

    private static function neutralSpan(): SourceSpan
    {
        $position = new SourcePosition(0, 1, 1);

        return new SourceSpan($position, $position);
    }
}
