<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Controller;

use YTassy\Tycms\CoreExts\Automation\Runtime\AutomationValueJson;
use YTassy\Tycms\CoreExts\Automation\Traits\TraitAutomationWF;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseController;
use YTassy\Tycms\CoreExts\Security\ExtensionApi\AnnotationsController\SecurityDoNotCheckPatterns;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Response\FileResponse;
use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam2;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam3;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;
use YTassy\Tycms\Routing\Annotation\NoTransaction;

/**
 * API d'administration et d'exécution des scénarios.
 *
 * La classe est exemptée du WAF : l'éditeur reçoit du code et l'exécution
 * reçoit une charge utile de scénario. Les ACL, le CSRF, la validation de
 * schéma et les contrôles du WF restent actifs.
 */
#[Controller(name: 'administrator_api_automation_', isApi: true, path: 'administrator/api/automation')]
#[SecurityDoNotCheckPatterns]
final class AutomationController extends BaseController
{
    use TraitAutomationWF;

    #[ControllerMethod(name: 'directory', path: 'directory')]
    public function directory(): ApiResponse
    {
        return new ApiResponse($this->wfAutomation->getDirectory());
    }

    #[ControllerMethod(name: 'reference', path: 'reference')]
    #[NoTransaction]
    public function reference(): FileResponse
    {
        return FileResponse::fromContent(
            $this->wfAutomation->referenceDocument(),
            'automatisation-reference.md',
            'text/markdown; charset=UTF-8',
        );
    }

    #[ControllerMethod(name: 'read', path: 'read')]
    #[ControllerParam1(name: 'scenarioId', required: true, varType: VarType::INT)]
    public function read(int $scenarioId): ApiResponse
    {
        return new ApiResponse(['scenario' => $this->wfAutomation->getScenario($scenarioId)]);
    }

    #[ControllerMethod(name: 'scenario_choices', path: 'scenario-choices')]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'triggerId', required: true, varType: VarType::STRING)]
    public function scenarioChoices(int $spaceId, string $triggerId): ApiResponse
    {
        return new ApiResponse($this->wfAutomation->getScenarioChoices($spaceId, $triggerId));
    }

    #[ControllerMethod(name: 'webhook_secret', path: 'webhook-secret', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'scenarioId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'mode', required: true, varType: VarType::STRING)]
    public function webhookSecret(int $scenarioId, string $mode): ApiResponse
    {
        return new ApiResponse($this->wfAutomation->generateWebhookSecret($scenarioId, $mode));
    }

    #[ControllerMethod(name: 'maintenance', path: 'maintenance')]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    public function maintenance(int $spaceId): ApiResponse
    {
        return new ApiResponse($this->wfAutomation->getMaintenance($spaceId));
    }

    #[ControllerMethod(name: 'maintenance_recover_event', path: 'maintenance/recover-event', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'eventId', required: true, varType: VarType::INT)]
    public function maintenanceRecoverEvent(int $eventId): ApiResponse
    {
        return new ApiResponse($this->wfAutomation->recoverEvent($eventId));
    }

    #[ControllerMethod(name: 'maintenance_fail_event', path: 'maintenance/fail-event', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'eventId', required: true, varType: VarType::INT)]
    public function maintenanceFailEvent(int $eventId): ApiResponse
    {
        return new ApiResponse($this->wfAutomation->failEvent($eventId));
    }

    #[ControllerMethod(name: 'maintenance_fail_run', path: 'maintenance/fail-run', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'runId', required: true, varType: VarType::INT)]
    public function maintenanceFailRun(int $runId): ApiResponse
    {
        return new ApiResponse($this->wfAutomation->failStuckRun($runId));
    }

    #[ControllerMethod(name: 'maintenance_cleanup', path: 'maintenance/cleanup', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'spaceId', required: true, varType: VarType::INT)]
    public function maintenanceCleanup(int $spaceId): ApiResponse
    {
        return new ApiResponse($this->wfAutomation->cleanupMaintenance($spaceId));
    }

    #[ControllerMethod(name: 'create', path: 'create', methods: [RequestMethod::POST])]
    #[ControllerParam1(
        name: 'data',
        required: true,
        schemaFile: __DIR__ . '/Schemas/AutomationScenarioCreate.php',
    )]
    public function create(object $data): ApiResponse
    {
        return new ApiResponse(['scenario' => $this->wfAutomation->createScenario($data)]);
    }

    #[ControllerMethod(name: 'save', path: 'save', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'scenarioId', required: true, varType: VarType::INT)]
    #[ControllerParam2(
        name: 'data',
        required: true,
        schemaFile: __DIR__ . '/Schemas/AutomationScenarioSave.php',
    )]
    public function save(int $scenarioId, object $data): ApiResponse
    {
        return new ApiResponse(['scenario' => $this->wfAutomation->saveScenario($scenarioId, $data)]);
    }

    #[ControllerMethod(name: 'publish', path: 'publish', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'scenarioId', required: true, varType: VarType::INT)]
    public function publish(int $scenarioId): ApiResponse
    {
        return new ApiResponse($this->wfAutomation->publishScenario($scenarioId));
    }

    #[ControllerMethod(name: 'archive', path: 'archive', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'scenarioId', required: true, varType: VarType::INT)]
    public function archive(int $scenarioId): ApiResponse
    {
        return new ApiResponse($this->wfAutomation->archiveScenario($scenarioId));
    }

    #[ControllerMethod(name: 'runs', path: 'runs')]
    #[ControllerParam1(name: 'scenarioId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'page', required: false, varType: VarType::INT)]
    #[ControllerParam3(name: 'perPage', required: false, varType: VarType::INT)]
    public function runs(int $scenarioId, ?int $page = null, ?int $perPage = null): ApiResponse
    {
        return new ApiResponse($this->wfAutomation->listRuns(
            $scenarioId,
            $page ?? 1,
            $perPage ?? 20,
        ));
    }

    #[ControllerMethod(name: 'run_detail', path: 'run/detail')]
    #[ControllerParam1(name: 'runId', required: true, varType: VarType::INT)]
    public function runDetail(int $runId): ApiResponse
    {
        return new ApiResponse($this->wfAutomation->getRun($runId));
    }

    #[ControllerMethod(name: 'dry_run', path: 'dry-run', methods: [RequestMethod::POST])]
    #[ControllerParam1(
        name: 'data',
        required: true,
        schemaFile: __DIR__ . '/Schemas/AutomationDryRun.php',
    )]
    #[NoTransaction]
    public function dryRun(object $data): ApiResponse
    {
        return new ApiResponse($this->wfAutomation->dryRun($data));
    }

    #[ControllerMethod(name: 'run', path: 'run', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'scenarioId', required: true, varType: VarType::INT)]
    #[ControllerParam2(name: 'input', required: true, varType: VarType::JSON)]
    #[NoTransaction]
    public function run(int $scenarioId, object $input): ApiResponse
    {
        $decoded = json_decode(
            json_encode($input, JSON_THROW_ON_ERROR),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        if (!is_array($decoded)) {
            ExceptionResponse::throwWarning('Entrée de scénario invalide.');
        }

        $result = $this->wfAutomation->runManual($scenarioId, $decoded);
        $result['output'] = json_decode(AutomationValueJson::encode($result['output']), true);

        return new ApiResponse($result);
    }

    #[ControllerMethod(name: 'rerun', path: 'rerun', methods: [RequestMethod::POST])]
    #[ControllerParam1(name: 'runId', required: true, varType: VarType::INT)]
    #[NoTransaction]
    public function rerun(int $runId): ApiResponse
    {
        $result = $this->wfAutomation->rerunRun($runId);
        $result['output'] = json_decode(AutomationValueJson::encode($result['output']), true);

        return new ApiResponse($result);
    }
}
