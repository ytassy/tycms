<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationParameterDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationTriggerRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\IAutomationAction;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\IAutomationProvider;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\Limits;
use YTassy\Tycms\CoreExts\Automation\Language\SourcePosition;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\Action\RegistryActionDispatcher;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionBudget;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionException;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionContext;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionJournal;
use YTassy\Tycms\CoreExts\Automation\Runtime\RuntimeServices;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class RegistryActionDispatcherTest extends FrameworkTestCase
{
    #[Test]
    public function dispatchesToTheDeclaredHandlerWithAnExplicitContext(): void
    {
        $app = $this->createApplication();
        $registry = new AutomationActionRegistry($app);
        $registry->registerProvider('plg_test', new DispatcherTestProvider([
            new AutomationActionDefinition(
                'test::action',
                'Test',
                DispatcherTestAction::class,
                parameters: [new AutomationParameterDefinition('a', 'int')],
            ),
        ]));

        $services = $this->services();
        $dispatcher = new RegistryActionDispatcher($app, $registry);

        $result = $dispatcher->dispatch('test::action', [1], $this->span(), $services);

        self::assertSame(['ok' => true], $result);
        self::assertSame(42, DispatcherTestAction::$lastContext?->principalId);
        self::assertSame(7, DispatcherTestAction::$lastContext?->spaceId);
        self::assertSame(99, DispatcherTestAction::$lastContext?->runId);
        self::assertSame(['a' => 1], DispatcherTestAction::$lastParams);
    }

    #[Test]
    public function mapsPositionalArgumentsToParameterNames(): void
    {
        $app = $this->createApplication();
        $registry = new AutomationActionRegistry($app);
        $registry->registerProvider('plg_test', new DispatcherTestProvider([
            new AutomationActionDefinition(
                'test::params',
                'Test params',
                DispatcherTestAction::class,
                parameters: [
                    new AutomationParameterDefinition('first', 'int'),
                    new AutomationParameterDefinition('second', 'string'),
                ],
            ),
        ]));

        $dispatcher = new RegistryActionDispatcher($app, $registry);
        $result = $dispatcher->dispatch('test::params', [1, 'x'], $this->span(), $this->services());

        self::assertSame(['ok' => true], $result);
        self::assertSame(['first' => 1, 'second' => 'x'], DispatcherTestAction::$lastParams);
    }

    #[Test]
    public function unknownActionIsRejected(): void
    {
        $app = $this->createApplication();
        $dispatcher = new RegistryActionDispatcher($app, new AutomationActionRegistry($app));

        $this->expectException(ExecutionException::class);
        $dispatcher->dispatch('test::missing', [], $this->span(), $this->services());
    }

    #[Test]
    public function unknownActionUsesActionUnavailableCode(): void
    {
        $app = $this->createApplication();
        $dispatcher = new RegistryActionDispatcher($app, new AutomationActionRegistry($app));

        try {
            $dispatcher->dispatch('test::missing', [], $this->span(), $this->services());
            self::fail('Une action inconnue doit échouer.');
        } catch (ExecutionException $exception) {
            self::assertSame(DiagnosticCode::ACTION_UNAVAILABLE, $exception->diagnostic->code);
        }
    }

    private function services(): RuntimeServices
    {
        $limits = new Limits();
        $context = ExecutionContext::create(
            ['team' => 3],
            ['id' => 42, 'type' => 'user'],
            ['id' => 7, 'timezone' => 'UTC'],
            ['id' => 99, 'scenarioId' => 1, 'versionId' => 2, 'trigger' => 'manual'],
        );

        return new RuntimeServices(
            $context,
            new ExecutionBudget($limits),
            new ExecutionJournal(),
            $limits,
        );
    }

    private function span(): SourceSpan
    {
        $position = new SourcePosition(0, 1, 1);

        return new SourceSpan($position, $position);
    }
}

final class DispatcherTestProvider implements IAutomationProvider
{
    /** @param list<AutomationActionDefinition> $actions */
    public function __construct(private readonly array $actions)
    {
    }

    public function coreOnRegisterAutomationActions(AutomationActionRegistry $registry): void
    {
        foreach ($this->actions as $action) {
            $registry->register($action);
        }
    }

    public function coreOnRegisterAutomationTriggers(AutomationTriggerRegistry $registry): void
    {
    }
}

final class DispatcherTestAction extends BaseAutomationAction implements IAutomationAction
{
    public static ?ActionContext $lastContext = null;

    /** @var array<string, mixed> */
    public static array $lastParams = [];

    public function execute(ActionContext $context, array $params): mixed
    {
        self::$lastContext = $context;
        self::$lastParams = $params;

        return ['ok' => true];
    }
}
