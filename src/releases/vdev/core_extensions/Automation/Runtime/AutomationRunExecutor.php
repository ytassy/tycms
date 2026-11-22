<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Runtime;

use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationEventBuffer;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationPrincipalMode;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationPrincipalType;
use YTassy\Tycms\CoreExts\Automation\Language\Diagnostic;
use YTassy\Tycms\CoreExts\Automation\Language\Limits;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\BuiltinFunctionCatalog;
use YTassy\Tycms\CoreExts\Automation\Language\ScenarioCompiler;
use YTassy\Tycms\CoreExts\Automation\Language\SourcePosition;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Plugin;
use YTassy\Tycms\CoreExts\Automation\Runtime\Action\RegistryActionDispatcher;
use YTassy\Tycms\CoreExts\Automation\Runtime\Builtin\BuiltinFunctionRegistry;
use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationHelpers;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Session\RunSession;

/** Orchestration synchrone d'un run publié. */
final class AutomationRunExecutor extends BaseHelper
{
    use TraitAutomationHelpers;

    /**
     * @param array<mixed> $input
     * @return array<string, mixed>
     */
    public function runManual(int $scenarioId, array $input, int $userId): array
    {
        if ($userId <= 0) {
            throw new \RuntimeException('Principal utilisateur requis.');
        }

        $scenario = $this->automationRepository->findScenarioById($scenarioId);

        if ($scenario === null) {
            throw new \RuntimeException('Scénario introuvable.');
        }

        if ((string) $scenario->status !== 'active') {
            throw new \RuntimeException('Scénario inactif.');
        }

        $version = $this->automationRepository->findPublishedVersion($scenarioId);

        if ($version === null) {
            throw new \RuntimeException('Aucune version publiée.');
        }

        [$executionApp, $principalId] = $this->resolveManualExecution($scenario, $userId);

        return $this->execute(new AutomationRunRequest(
            scenario: $scenario,
            version: $version,
            input: $input,
            triggerId: $this->triggerIdFor($scenarioId),
            eventRowId: null,
            eventUuid: null,
            dedupKey: null,
            depth: 0,
            parentRunId: null,
            principalType: AutomationPrincipalType::USER,
            principalId: $principalId,
            principalLabel: null,
            correlationId: null,
            executionApp: $executionApp,
        ));
    }

    /**
     * Exécute un scénario abonné à un événement déjà persisté.
     *
     * Retourne false lorsque le scénario est ignoré : mode de principal
     * indisponible, version absente ou événement sans utilisateur pour un
     * déclencheur en mode trigger. Sinon, retourne le résultat du run enrichi
     * de `scenario_id` pour que les producteurs puissent le relier à l'appel.
     *
     * @return array<string, mixed>|false
     */
    public function runFromEvent(int $scenarioId, object $event): array|false
    {
        $scenario = $this->automationRepository->findScenarioById($scenarioId);

        if ($scenario === null || (string) $scenario->status !== 'active') {
            return false;
        }

        $version = $this->automationRepository->findPublishedVersion($scenarioId);

        if ($version === null) {
            return false;
        }

        $spaceId = (int) $scenario->space_id;
        $mode = (string) $scenario->principal_mode;
        $executionApp = $this->app;
        $principalType = (string) ($event->principal_type ?? '');
        $principalId = (int) ($event->principal_id ?? 0);

        if ($mode === AutomationPrincipalMode::AUTHOR) {
            $authorId = (int) ($scenario->execution_user_id ?? 0);

            if ($authorId <= 0) {
                $this->logWarning(
                    'Le scénario n’a pas d’auteur disponible.',
                    ['space_id' => $spaceId, 'scenario_id' => $scenarioId],
                    'AUTOMATION_PRINCIPAL_UNAVAILABLE',
                    'automation',
                );

                return false;
            }

            // Le mode author exécute avec les droits de l'auteur publié, pas
            // avec ceux de l'utilisateur qui a provoqué l'événement.
            $executionApp = $this->createRunApplication($authorId, $spaceId);
            $principalType = AutomationPrincipalType::USER;
            $principalId = $authorId;
        } elseif ($mode === AutomationPrincipalMode::TRIGGER) {
            if ($principalType !== AutomationPrincipalType::USER || $principalId <= 0) {
                $this->logWarning(
                    'Un déclencheur sans principal utilisateur a été ignoré.',
                    ['space_id' => $spaceId, 'trigger_id' => (string) $event->trigger_id],
                    'AUTOMATION_PRINCIPAL_UNAVAILABLE',
                    'automation',
                );

                return false;
            }
        } elseif ($mode === AutomationPrincipalMode::SERVICE) {
            $this->logWarning(
                'Le mode service n’est pas disponible.',
                ['space_id' => $spaceId, 'scenario_id' => $scenarioId],
                'AUTOMATION_PRINCIPAL_UNAVAILABLE',
                'automation',
            );

            return false;
        } else {
            return false;
        }

        $input = json_decode((string) $event->payload, true);

        if (!is_array($input)) {
            $this->logWarning(
                'La charge utile de l’événement est invalide.',
                ['space_id' => $spaceId, 'trigger_id' => (string) $event->trigger_id],
                'AUTOMATION_EVENT_PAYLOAD_INVALID',
                'automation',
            );

            return false;
        }

        $correlationId = $event->correlation_id ?? null;

        $result = $this->execute(new AutomationRunRequest(
            scenario: $scenario,
            version: $version,
            input: $input,
            triggerId: (string) $event->trigger_id,
            eventRowId: (int) $event->id,
            eventUuid: (string) $event->event_id,
            dedupKey: (string) $event->event_id,
            depth: (int) $event->depth,
            parentRunId: $this->parentRunIdFor($event, $scenarioId),
            principalType: $principalType,
            principalId: $principalId,
            principalLabel: null,
            correlationId: is_string($correlationId) && $correlationId !== '' ? $correlationId : null,
            executionApp: $executionApp,
        ));

        $result['scenario_id'] = $scenarioId;

        return $result;
    }

    /**
     * Exécute un scénario dans la transaction de l'appelant.
     *
     * Aucune transaction n'est ouverte ici. Les runs et étapes sont annulés
     * avec la transaction du producteur. La notification d'échec est laissée
     * à l'appelant, qui décide de commiter ou non.
     *
     * @param array<mixed> $input
     * @return array<string, mixed>
     */
    public function runNow(
        int $scenarioId,
        array $input,
        int $actorId,
        string $triggerId,
        ?string $dedupKey = null,
        int $depth = 0,
    ): array {
        $scenario = $this->automationRepository->findScenarioById($scenarioId);

        if ($scenario === null || (string) $scenario->status !== 'active') {
            return self::failedNow('Scénario introuvable ou inactif.');
        }

        $version = $this->automationRepository->findPublishedVersion($scenarioId);

        if ($version === null) {
            return self::failedNow('Aucune version publiée.');
        }

        $spaceId = (int) $scenario->space_id;
        $mode = (string) $scenario->principal_mode;
        $executionApp = $this->app;
        $principalType = AutomationPrincipalType::USER;
        $principalId = $actorId;

        if ($mode === AutomationPrincipalMode::AUTHOR) {
            $authorId = (int) ($scenario->execution_user_id ?? 0);

            if ($authorId <= 0) {
                return self::failedNow('Auteur du scénario indisponible.');
            }

            $executionApp = $this->createRunApplication($authorId, $spaceId);
            $principalId = $authorId;
        } elseif ($mode === AutomationPrincipalMode::TRIGGER) {
            if ($actorId <= 0) {
                return self::failedNow('Principal utilisateur requis pour ce déclencheur.');
            }
        } elseif ($mode === AutomationPrincipalMode::SERVICE) {
            return self::failedNow('Le mode service n’est pas disponible.');
        } else {
            return self::failedNow('Mode de principal invalide.');
        }

        return $this->execute(new AutomationRunRequest(
            scenario: $scenario,
            version: $version,
            input: $input,
            triggerId: $triggerId,
            eventRowId: null,
            eventUuid: null,
            dedupKey: $dedupKey,
            depth: $depth,
            parentRunId: null,
            principalType: $principalType,
            principalId: $principalId,
            principalLabel: null,
            correlationId: null,
            executionApp: $executionApp,
            notifyOnFailure: false,
        ));
    }

    /** @return array<string, mixed> */
    private static function failedNow(string $message): array
    {
        return [
            'run_id' => null,
            'status' => 'failed',
            'output' => null,
            'message' => $message,
            'diagnostics' => [$message],
        ];
    }

    /**
     * Principal d'exécution pour une simulation ou un diagnostic.
     *
     * @return array<string, mixed>
     */
    public function principalFor(int $userId): array
    {
        return $this->loadPrincipal($userId);
    }

    /**
     * Space d'exécution pour une simulation ou un diagnostic.
     *
     * @return array<string, mixed>
     */
    public function spaceFor(int $spaceId): array
    {
        return $this->loadSpace($spaceId);
    }

    /** @return array{0: Application, 1: int} */
    private function resolveManualExecution(object $scenario, int $userId): array
    {
        $spaceId = (int) $scenario->space_id;
        $principalMode = (string) $scenario->principal_mode;

        if ($principalMode === AutomationPrincipalMode::AUTHOR) {
            $authorId = (int) ($scenario->execution_user_id ?? 0);

            if ($authorId <= 0) {
                throw new \RuntimeException('Auteur du scénario indisponible.');
            }

            return [$this->createRunApplication($authorId, $spaceId), $authorId];
        }

        if ($principalMode === AutomationPrincipalMode::SERVICE) {
            throw new \RuntimeException('Mode service non disponible.');
        }

        if ($principalMode !== AutomationPrincipalMode::TRIGGER) {
            throw new \RuntimeException('Mode de principal invalide.');
        }

        return [$this->app, $userId];
    }

    /** @return array<string, mixed> */
    private function execute(AutomationRunRequest $request): array
    {
        $scenario = $request->scenario;
        $spaceId = (int) $scenario->space_id;

        $safeInput = ValueTools::assertSafe($request->input, new Limits(), self::neutralSpan());

        if (!is_array($safeInput)) {
            throw new \RuntimeException('Entrée de scénario invalide.');
        }

        $principal = $this->loadPrincipal($request->principalId);
        $space = $this->loadSpace($spaceId);

        try {
            $runId = $this->automationRepository->createRun(
                $spaceId,
                (int) $scenario->id,
                (int) $request->version->id,
                $request->triggerId,
                $request->eventRowId,
                $request->dedupKey,
                $request->principalType,
                $request->principalId,
                $request->principalLabel,
                AutomationValueJson::encode($safeInput),
                $request->depth,
                $request->parentRunId,
            );
        } catch (\mysqli_sql_exception $exception) {
            // La clé unique (scénario, dedup_key) protège d'un double drain.
            if ($exception->getCode() === 1062) {
                return [
                    'run_id' => null,
                    'status' => 'skipped',
                    'output' => null,
                    'message' => 'Run déjà enregistré pour cette déduplication.',
                    'diagnostics' => [],
                ];
            }

            throw $exception;
        }

        $limits = new Limits();
        $actionRegistry = $this->actionRegistryFor($request->executionApp);
        $catalog = new AutomationFunctionCatalog(BuiltinFunctionCatalog::create(), $actionRegistry);
        $compiled = (new ScenarioCompiler($catalog, $limits))->compile((string) $request->version->source);

        if (!$compiled->isSuccessful() || $compiled->program === null) {
            $diagnostic = $compiled->diagnostics[0] ?? null;
            $message = $diagnostic?->message ?? 'Compilation échouée.';
            $this->automationRepository->finishRun(
                $runId,
                'failed',
                null,
                $message,
                $diagnostic?->code ?? 'COMPILE_ERROR',
                $message,
                0,
                0,
                0,
                0,
            );
            if ($request->notifyOnFailure) {
                $this->notifyFailure(
                    $spaceId,
                    $runId,
                    (string) $scenario->name,
                    $request->triggerId,
                    $diagnostic?->code ?? 'COMPILE_ERROR',
                    $message,
                );
            }

            return [
                'run_id' => $runId,
                'status' => 'failed',
                'output' => null,
                'message' => $message,
                'diagnostics' => array_map(
                    static fn (Diagnostic $item): string => (string) $item,
                    $compiled->diagnostics,
                ),
            ];
        }

        $dispatcher = new RegistryActionDispatcher($request->executionApp, $actionRegistry);
        $context = ExecutionContext::create(
            $safeInput,
            $principal,
            $space,
            [
                'id' => $runId,
                'scenarioId' => (int) $scenario->id,
                'versionId' => (int) $request->version->id,
                'trigger' => $request->triggerId,
            ],
            $dispatcher,
        );

        $buffer = $this->eventBufferFor($request->executionApp);
        $hasEventContext = $request->eventUuid !== null;

        // Les actions exécutées pendant ce run émettent des événements enfants
        // avec la profondeur et la corrélation du run courant.
        if ($hasEventContext) {
            $buffer->enterEventContext($request->eventUuid, $request->depth, $request->correlationId);
        }

        try {
            $result = (new Interpreter($catalog, new BuiltinFunctionRegistry(), $limits))
                ->execute($compiled->program, $context);
        } catch (\Throwable $exception) {
            $this->automationRepository->finishRun(
                $runId,
                'failed',
                null,
                'Erreur interne du run.',
                'RUNTIME_ERROR',
                'Erreur interne du run.',
                0,
                0,
                0,
                0,
            );
            if ($request->notifyOnFailure) {
                $this->notifyFailure(
                    $spaceId,
                    $runId,
                    (string) $scenario->name,
                    $request->triggerId,
                    'RUNTIME_ERROR',
                    'Erreur interne du run.',
                );
            }

            throw $exception;
        } finally {
            if ($hasEventContext) {
                $buffer->leaveEventContext();
            }
        }

        $this->persistResult(
            $runId,
            $spaceId,
            $result,
            (string) $scenario->name,
            $request->triggerId,
            $request->notifyOnFailure,
        );
        $this->drainChainedEvents($buffer, $request->executionApp);

        return [
            'run_id' => $runId,
            'status' => $result->status->value,
            'output' => $result->output,
            'message' => $result->message,
            'diagnostics' => array_map(
                static fn (Diagnostic $item): string => (string) $item,
                $result->diagnostics,
            ),
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

        return $plugin instanceof Plugin ? $plugin->getActionRegistry() : null;
    }

    private function eventBufferFor(Application $application): AutomationEventBuffer
    {
        $buffer = $application->getOrAddSharedExtensionsClass(AutomationEventBuffer::class);

        if (!$buffer instanceof AutomationEventBuffer) {
            throw new \RuntimeException('Le buffer d’événements est indisponible.');
        }

        return $buffer;
    }

    /**
     * Un run author isolé possède son propre buffer : il faut le drainer pour
     * que les événements enfants ne restent pas en attente. Pour l'Application
     * courante, le hook de route draine après le commit ; un drain immédiat
     * pourrait traiter des effets d'une transaction encore ouverte.
     */
    private function drainChainedEvents(AutomationEventBuffer $buffer, Application $executionApp): void
    {
        if ($executionApp === $this->app) {
            return;
        }

        try {
            $buffer->drain(true);
        } catch (\Throwable $exception) {
            $this->logError(
                'Le drainage des événements enfants a échoué.',
                [],
                'AUTOMATION_EVENT_DRAIN_FAILED',
                'automation',
            );
            $this->logException($exception);
        }
    }

    private function triggerIdFor(int $scenarioId): string
    {
        $trigger = $this->automationRepository->findTrigger($scenarioId);

        return $trigger === null ? 'manual' : (string) $trigger->trigger_id;
    }

    private function parentRunIdFor(object $event, int $scenarioId): ?int
    {
        $causationId = $event->causation_id ?? null;

        if (!is_string($causationId) || $causationId === '') {
            return null;
        }

        $parentEvent = $this->automationRepository->findEventByEventId($causationId);

        if ($parentEvent === null) {
            return null;
        }

        $parentRun = $this->automationRepository->findRunByEventId(
            (int) $parentEvent->id,
            $scenarioId,
        );

        return $parentRun === null ? null : (int) $parentRun->id;
    }

    /** @return array<string, mixed> */
    private function loadPrincipal(int $userId): array
    {
        $row = $this->db->selectOneOrNull(
            'SELECT id, first_name, last_name, email FROM users WHERE id = % LIMIT 1',
            [$userId],
        );

        if ($row === null) {
            throw new \RuntimeException('Principal introuvable.');
        }

        return [
            'id' => $userId,
            'type' => AutomationPrincipalType::USER,
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

    private function persistResult(
        int $runId,
        int $spaceId,
        ExecutionResult $result,
        string $scenarioName,
        string $triggerId,
        bool $notifyOnFailure,
    ): void {
        $diagnostic = $result->diagnostics[0] ?? null;

        $this->automationRepository->finishRun(
            $runId,
            $result->status->value,
            AutomationValueJson::encode($result->output),
            $result->message,
            $diagnostic?->code,
            $diagnostic?->message,
            $result->journal->count(),
            $result->loopIterations,
            $result->actionCalls,
            (int) round($result->durationSeconds * 1000),
        );

        if ($result->status === ExecutionStatus::FAILED && $notifyOnFailure) {
            $this->notifyFailure(
                $spaceId,
                $runId,
                $scenarioName,
                $triggerId,
                $diagnostic?->code,
                $diagnostic?->message,
            );
        }

        try {
            foreach ($result->journal->all() as $step) {
                $this->automationRepository->addStep(
                    $runId,
                    $step->sequence,
                    $step->kind,
                    $step->identifier,
                    $step->status,
                    AutomationValueJson::encode($step->arguments),
                    AutomationValueJson::encode($step->result),
                    $step->error?->code,
                    $step->error?->message,
                    $step->span->start->line,
                    $step->span->start->column,
                    $step->span->end->line,
                    $step->span->end->column,
                );
            }
        } catch (\Throwable $exception) {
            $this->logError(
                'La persistance des étapes du run a échoué.',
                ['space_id' => $spaceId],
                'AUTOMATION_STEPS_PERSIST_FAILED',
                'automation',
            );
            $this->logException($exception);
        }
    }

    private function notifyFailure(
        int $spaceId,
        int $runId,
        string $scenarioName,
        string $triggerId,
        ?string $errorCode,
        ?string $errorMessage,
    ): void {
        try {
            $this->automationFailureNotifier->notify(
                $spaceId,
                $runId,
                $scenarioName,
                $triggerId,
                $errorCode,
                $errorMessage,
            );
        } catch (\Throwable $exception) {
            $this->logException($exception);
        }
    }

    private static function neutralSpan(): SourceSpan
    {
        $position = new SourcePosition(0, 1, 1);

        return new SourceSpan($position, $position);
    }
}
