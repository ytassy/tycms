<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationParameterDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationTriggerDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationTriggerRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\IAutomationAction;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\IAutomationProvider;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\BuiltinFunctionCatalog;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionEffect;
use YTassy\Tycms\CoreExts\Automation\Runtime\AutomationFunctionCatalog;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class AutomationFunctionCatalogTest extends FrameworkTestCase
{
    #[Test]
    public function mergesBuiltinsAndActionDefinitions(): void
    {
        $app = $this->createApplication();
        $registry = new AutomationActionRegistry($app);
        $registry->registerProvider('plg_test', new CatalogTestProvider([
            new AutomationActionDefinition(
                'test::action',
                'Test',
                CatalogTestAction::class,
                parameters: [
                    new AutomationParameterDefinition('required', 'string', true),
                    new AutomationParameterDefinition('optional', 'string', false),
                ],
            ),
        ]));

        $catalog = new AutomationFunctionCatalog(BuiltinFunctionCatalog::create(), $registry);

        self::assertNotNull($catalog->find('str::length'));
        $action = $catalog->find('test::action');
        self::assertNotNull($action);
        self::assertSame(1, $action->minArity);
        self::assertSame(2, $action->maxArity);
        self::assertNull($catalog->find('test::missing'));
    }

    #[Test]
    public function excludedEffectsBecomeUnknownFunctions(): void
    {
        $app = $this->createApplication();
        $registry = new AutomationActionRegistry($app);
        $registry->registerProvider('plg_test', new CatalogTestProvider([
            new AutomationActionDefinition(
                'test::read',
                'Read',
                CatalogTestAction::class,
                effect: FunctionEffect::READ,
            ),
            new AutomationActionDefinition(
                'test::mail',
                'Mail',
                CatalogTestAction::class,
                effect: FunctionEffect::EXTERNAL,
            ),
        ]));

        $catalog = new AutomationFunctionCatalog(
            BuiltinFunctionCatalog::create(),
            $registry,
            [FunctionEffect::EXTERNAL],
        );

        // L'action en lecture reste disponible, l'action à effet externe
        // devient inconnue à la compilation.
        self::assertNotNull($catalog->find('test::read'));
        self::assertNull($catalog->find('test::mail'));
    }
}

final class CatalogTestProvider implements IAutomationProvider
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

final class CatalogTestAction extends BaseAutomationAction implements IAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        return null;
    }
}
