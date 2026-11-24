<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Automation\Annotation\ActionParam;
use YTassy\Tycms\CoreExts\Automation\Annotation\ActionPermission;
use YTassy\Tycms\CoreExts\Automation\Annotation\ActionTransform;
use YTassy\Tycms\CoreExts\Automation\Annotation\AutomationAction;
use YTassy\Tycms\CoreExts\Automation\Annotation\ExposeAutomationActions;
use YTassy\Tycms\CoreExts\Automation\Annotation\WorkflowActionScanner;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationTriggerRegistry;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\IAutomationProvider;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\WorkflowMethodAction;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\FunctionEffect;
use YTassy\Tycms\CoreExts\Automation\Language\SourcePosition;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionException;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;
use YTassy\Tycms\WF\BaseHelper;

final class WorkflowActionAnnotationsTest extends FrameworkTestCase
{
    #[Test]
    public function scannerBuildsTheExpectedDefinition(): void
    {
        $definitions = WorkflowActionScanner::definitionsFor([AnnotatedWorkflowFixture::class]);

        self::assertSame(
            ['test::doubleValue', 'test::projectItems', 'test::requiredParam'],
            array_map(static fn ($definition): string => $definition->id, $definitions),
        );

        $double = $definitions[0];

        self::assertSame('Double', $double->label);
        self::assertSame('int', $double->returnType);
        self::assertSame(FunctionEffect::READ, $double->effect);
        self::assertSame('scenario', $double->targetSpace);
        self::assertSame(['x'], array_map(
            static fn ($parameter): string => $parameter->name,
            $double->parameters,
        ));

        $param = $double->parameters[0];
        self::assertSame('int', $param->type);
        self::assertTrue($param->required);
        self::assertSame('Valeur', $param->label);

        self::assertSame(AnnotatedWorkflowFixture::class, $double->workflowMethod->workflowClass);
        self::assertSame('doubleValue', $double->workflowMethod->methodName);
        self::assertNull($double->workflowMethod->transform);
    }

    #[Test]
    public function scannerCollectsPermissionsTransformAndOrderedParameters(): void
    {
        $definitions = WorkflowActionScanner::definitionsFor([AnnotatedWorkflowFixture::class]);

        $byId = [];

        foreach ($definitions as $definition) {
            $byId[$definition->id] = $definition;
        }

        self::assertSame(['my_permission'], $byId['test::requiredParam']->requiredPermissions);

        $project = $byId['test::projectItems'];
        self::assertSame(['a', 'b'], array_map(
            static fn ($parameter): string => $parameter->name,
            $project->parameters,
        ));
        self::assertSame([FixtureTransform::class, 'project'], $project->workflowMethod->transform);
    }

    #[Test]
    public function scannerRejectsAClassWithoutExposeAttribute(): void
    {
        $this->expectException(TechException::class);

        WorkflowActionScanner::definitionsFor([\stdClass::class]);
    }

    #[Test]
    public function scannerRejectsAnActionParamUnknownToTheMethod(): void
    {
        $this->expectException(TechException::class);

        WorkflowActionScanner::definitionsFor([BrokenParamsWorkflowFixture::class]);
    }

    #[Test]
    public function registryRegistersAnnotatedActionsAndBuildsTheWorkflowHandler(): void
    {
        $app = $this->createApplication();
        $registry = new AutomationActionRegistry($app);
        $registry->registerProvider('plg_test', new AnnotatedProvider());

        self::assertTrue($registry->has('test::doubleValue'));
        self::assertSame('plg_test', $registry->ownerOf('test::doubleValue'));
        self::assertInstanceOf(WorkflowMethodAction::class, $registry->createHandler('test::doubleValue'));
    }

    #[Test]
    public function registryRejectsDuplicateAnnotatedAction(): void
    {
        $app = $this->createApplication();
        $registry = new AutomationActionRegistry($app);

        $this->expectException(\LogicException::class);

        $registry->registerProvider('plg_test', new class implements IAutomationProvider {
            public function coreOnRegisterAutomationActions(AutomationActionRegistry $registry): void
            {
                WorkflowActionScanner::register($registry, [AnnotatedWorkflowFixture::class]);
                WorkflowActionScanner::register($registry, [AnnotatedWorkflowFixture::class]);
            }

            public function coreOnRegisterAutomationTriggers(AutomationTriggerRegistry $registry): void
            {
            }
        });
    }

    #[Test]
    public function workflowHandlerCoercesArgumentsAndReturnsTheResult(): void
    {
        $result = $this->execute('test::doubleValue', ['x' => 21]);

        self::assertSame(42, $result);
    }

    #[Test]
    public function workflowHandlerAppliesTheOutputTransform(): void
    {
        $result = $this->execute('test::projectItems', ['a' => 'un', 'b' => 'deux']);

        self::assertSame([['id' => 1], ['id' => 2]], $result);
    }

    #[Test]
    public function workflowHandlerRejectsAWrongArgumentType(): void
    {
        $this->expectException(ExecutionException::class);

        $this->execute('test::doubleValue', ['x' => 'pas un entier']);
    }

    #[Test]
    public function workflowHandlerReportsAMissingRequiredArgument(): void
    {
        try {
            $this->execute('test::requiredParam', []);
            self::fail('Un argument obligatoire manquant doit être refusé.');
        } catch (ExecutionException $exception) {
            self::assertSame(DiagnosticCode::INVALID_ARGUMENT, $exception->diagnostic->code);
        }
    }

    /** @param array<string, mixed> $params */
    private function execute(string $id, array $params): mixed
    {
        $app = $this->createApplication();
        $registry = new AutomationActionRegistry($app);
        $registry->registerProvider('plg_test', new AnnotatedProvider());

        $handler = $registry->createHandler($id);

        return $handler->execute(new ActionContext(
            'user',
            42,
            7,
            99,
            1,
            2,
            $this->span(),
        ), $params);
    }

    private function span(): SourceSpan
    {
        $position = new SourcePosition(0, 1, 1);

        return new SourceSpan($position, $position);
    }
}

final class AnnotatedProvider implements IAutomationProvider
{
    public function coreOnRegisterAutomationActions(AutomationActionRegistry $registry): void
    {
        WorkflowActionScanner::register($registry, [AnnotatedWorkflowFixture::class]);
    }

    public function coreOnRegisterAutomationTriggers(AutomationTriggerRegistry $registry): void
    {
    }
}

#[ExposeAutomationActions(namespace: 'test')]
final class AnnotatedWorkflowFixture extends BaseHelper
{
    #[AutomationAction(
        id: 'test::doubleValue',
        label: 'Double',
        description: 'Double la valeur.',
        returnType: 'int',
        effect: FunctionEffect::READ,
    )]
    #[ActionParam(name: 'x', type: 'int', label: 'Valeur', description: 'Valeur à doubler.')]
    public function doubleValue(int $x): int
    {
        return $x * 2;
    }

    #[AutomationAction(id: 'test::projectItems', returnType: 'array', effect: FunctionEffect::READ)]
    #[ActionParam(name: 'a', type: 'string')]
    #[ActionParam(name: 'b', type: 'string')]
    #[ActionTransform(FixtureTransform::class, 'project')]
    public function projectItems(string $a, string $b): array
    {
        return ['items' => [['id' => 1, 'label' => $a], ['id' => 2, 'label' => $b]]];
    }

    #[AutomationAction(id: 'test::requiredParam', returnType: 'int')]
    #[ActionParam(name: 'x', type: 'int')]
    #[ActionPermission('my_permission')]
    public function requiredParam(int $x): int
    {
        return $x;
    }
}

/** Projette le résultat brut d'une action vers des valeurs sûres. */
final class FixtureTransform
{
    /** @param array<string, mixed> $raw @return list<array{id: int}> */
    public static function project(array $raw): array
    {
        return array_map(
            static fn (array $item): array => ['id' => (int) $item['id']],
            $raw['items'],
        );
    }
}

#[ExposeAutomationActions(namespace: 'test')]
final class BrokenParamsWorkflowFixture extends BaseHelper
{
    #[AutomationAction(id: 'test::broken', returnType: 'int')]
    #[ActionParam(name: 'unknownParam', type: 'int')]
    public function broken(int $x): int
    {
        return $x;
    }
}