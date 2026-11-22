<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationTriggerDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationTriggerRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\IAutomationProvider;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\IAutomationAction;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class AutomationExtensionApiTest extends FrameworkTestCase
{
    #[Test]
    public function actionsAreRegisteredWithTheirOwnerAndInstanciatedLazily(): void
    {
        $app = $this->createApplication();
        $registry = new AutomationActionRegistry($app);

        $provider = new TestAutomationProvider([
            new AutomationActionDefinition('test::action', 'Test', TestAutomationHandler::class),
        ]);

        $registry->registerProvider('plg_test', $provider);

        self::assertTrue($registry->has('test::action'));
        self::assertSame('plg_test', $registry->ownerOf('test::action'));
        self::assertInstanceOf(TestAutomationHandler::class, $registry->createHandler('test::action'));
    }

    #[Test]
    public function duplicateActionIdentifierIsRejected(): void
    {
        $registry = new AutomationActionRegistry($this->createApplication());
        $definition = new AutomationActionDefinition('test::action', 'Test', TestAutomationHandler::class);

        $registry->registerProvider('plg_test', new TestAutomationProvider([$definition]));

        $this->expectException(\LogicException::class);
        $registry->registerProvider('plg_test', new TestAutomationProvider([$definition]));
    }

    #[Test]
    public function invalidActionIdentifierAndHandlerAreRejected(): void
    {
        $registry = new AutomationActionRegistry($this->createApplication());

        $this->expectException(\LogicException::class);
        $registry->registerProvider('plg_test', new TestAutomationProvider([
            new AutomationActionDefinition('bad', 'Test', TestAutomationHandler::class),
        ]));
    }

    #[Test]
    public function handlerMustExtendTheActionBase(): void
    {
        $registry = new AutomationActionRegistry($this->createApplication());

        $this->expectException(\LogicException::class);
        $registry->registerProvider('plg_test', new TestAutomationProvider([
            new AutomationActionDefinition('test::action', 'Test', \stdClass::class),
        ]));
    }

    #[Test]
    public function triggersAreRegisteredWithOwnerAndValidated(): void
    {
        $registry = new AutomationTriggerRegistry($this->createApplication());
        $provider = new TestAutomationProvider([], [
            new AutomationTriggerDefinition('core.user.created', 'Création d’utilisateur'),
        ]);

        $registry->registerProvider('plg_core', $provider);

        self::assertTrue($registry->has('core.user.created'));
        self::assertSame('plg_core', $registry->ownerOf('core.user.created'));
    }

    #[Test]
    public function invalidTriggerIdentifierIsRejected(): void
    {
        $registry = new AutomationTriggerRegistry($this->createApplication());

        $this->expectException(\LogicException::class);
        $registry->registerProvider('plg_core', new TestAutomationProvider([], [
            new AutomationTriggerDefinition('bad', 'Test'),
        ]));
    }
}

final class TestAutomationProvider implements IAutomationProvider
{
    /**
     * @param list<AutomationActionDefinition> $actions
     * @param list<AutomationTriggerDefinition> $triggers
     */
    public function __construct(
        private readonly array $actions = [],
        private readonly array $triggers = [],
    ) {
    }

    public function coreOnRegisterAutomationActions(AutomationActionRegistry $registry): void
    {
        foreach ($this->actions as $action) {
            $registry->register($action);
        }
    }

    public function coreOnRegisterAutomationTriggers(AutomationTriggerRegistry $registry): void
    {
        foreach ($this->triggers as $trigger) {
            $registry->register($trigger);
        }
    }
}

final class TestAutomationHandler extends BaseAutomationAction implements IAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        return ['ok' => true];
    }
}
