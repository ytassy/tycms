<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationParameterDefinition;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationTriggerRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\IAutomationProvider;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\IAutomationAction;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionEffect;
use YTassy\Tycms\CoreExts\Automation\Mcp\McpToolCatalog;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class McpToolCatalogTest extends FrameworkTestCase
{
    #[Test]
    public function hidesExternalActionsFromTheMcpCatalogue(): void
    {
        $app = $this->createApplication();
        $registry = new AutomationActionRegistry($app);
        $registry->registerProvider('plg_test', new McpCatalogProvider([
            new AutomationActionDefinition(
                'test::read',
                'Lire',
                McpCatalogAction::class,
                effect: FunctionEffect::READ,
                parameters: [new AutomationParameterDefinition('id', 'int')],
            ),
            new AutomationActionDefinition(
                'test::mail',
                'Mail',
                McpCatalogAction::class,
                effect: FunctionEffect::EXTERNAL,
            ),
        ]));

        $actions = (new McpToolCatalog($registry))->actions();

        // L'action externe n'est jamais proposée au client MCP.
        self::assertSame(['test::read'], array_column($actions, 'id'));
        self::assertSame('id', $actions[0]['parameters'][0]['name']);
    }

    #[Test]
    public function emptyRegistryProducesAnEmptyCatalogue(): void
    {
        self::assertSame([], (new McpToolCatalog(null))->actions());
    }

    #[Test]
    public function contextAndReferenceDescribeTheLanguage(): void
    {
        $catalog = new McpToolCatalog(null);

        $context = $catalog->context();
        self::assertSame('function main()', $context['entryPoint']);
        self::assertNotSame([], $context['variables']);

        $reference = $catalog->reference();
        self::assertNotSame([], $reference['functions']);
        self::assertSame([], $reference['actions']);
    }
}

final class McpCatalogProvider implements IAutomationProvider
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

final class McpCatalogAction extends BaseAutomationAction implements IAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        return null;
    }
}