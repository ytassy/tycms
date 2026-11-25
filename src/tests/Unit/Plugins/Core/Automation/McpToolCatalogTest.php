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
use YTassy\Tycms\CoreExts\Automation\Mcp\McpLanguageGuide;
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
        // La syntaxe d'appel est rappelée dans le contexte, pas seulement dans
        // la référence : un agent qui ne lit qu'un document ne doit pas se
        // tromper sur les arguments nommés.
        self::assertStringContainsString('positionnels', $context['callStyle']);

        $reference = $catalog->reference();
        self::assertNotSame([], $reference['functions']);
        self::assertSame([], $reference['actions']);
    }

    /**
     * Le document servi par le MCP est la seule documentation de l'agent : il
     * doit rester autosuffisant (architecture, vocabulaire, syntaxe) et à jour.
     */
    #[Test]
    public function referenceIsSelfSufficientForAnAgentWithoutOtherDocs(): void
    {
        $reference = (new McpToolCatalog(null))->reference();

        // Architecture et vocabulaire expliqués, pas seulement supposés.
        self::assertArrayHasKey('architecture', $reference);
        self::assertNotSame([], $reference['architecture']['overview']);
        self::assertNotSame([], $reference['architecture']['howToProceed']);

        // Syntaxe réelle : entrée, appels positionnels, exemples exécutables.
        $syntax = $reference['syntax'];
        self::assertStringContainsString('main()', $syntax['entryPoint']);
        self::assertStringContainsString('POSITIONNELS', $syntax['callStyle']);
        self::assertNotSame([], $syntax['examples']);

        // La ponctuation est une règle, pas seulement montrée par les exemples :
        // le « ; » final est la cause d'erreur la plus fréquente d'un agent.
        self::assertStringContainsString('point-virgule', $syntax['semicolons']);
        self::assertStringContainsString('OBLIGATOIRE', $syntax['semicolons']);
        self::assertStringContainsString('accolade fermante', $syntax['semicolons']);

        foreach ($syntax['examples'] as $example) {
            self::assertStringContainsString('function main()', $example['code']);
        }

        // Le rendu Markdown reste généré depuis le même contrat.
        $markdown = McpLanguageGuide::markdown();
        self::assertStringContainsString('## Le vocabulaire du serveur', $markdown);
        self::assertStringContainsString('## Syntaxe du langage', $markdown);
    }

    /**
     * Chaque action porte un exemple d'appel utilisable : c'est lui qui évite
     * à l'agent d'essayer des arités au hasard.
     */
    #[Test]
    public function everyActionExposesAPositionalCallExample(): void
    {
        $app = $this->createApplication();
        $registry = new AutomationActionRegistry($app);
        $registry->registerProvider('plg_test', new McpCatalogProvider([
            new AutomationActionDefinition(
                'test::call',
                'Appel',
                McpCatalogAction::class,
                effect: FunctionEffect::READ,
                parameters: [
                    new AutomationParameterDefinition('userId', 'int', true),
                    new AutomationParameterDefinition('label', 'string', false, 'defaut'),
                    new AutomationParameterDefinition('flag', 'bool', false, true),
                ],
            ),
        ]));

        $actions = (new McpToolCatalog($registry))->actions();

        // Les arguments suivent l'ordre des paramètres, sans clé nommée, et les
        // valeurs par défaut apparaissent aux positions correspondantes.
        self::assertSame(
            'test::call(0 /* userId */, "defaut", true);',
            $actions[0]['call'],
        );
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