<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\Language\DiagnosticCode;
use YTassy\Tycms\CoreExts\Automation\Language\Limits;
use YTassy\Tycms\CoreExts\Automation\Language\SourcePosition;
use YTassy\Tycms\CoreExts\Automation\Language\SourceSpan;
use YTassy\Tycms\CoreExts\Automation\Runtime\Action\ActionDispatcher;
use YTassy\Tycms\CoreExts\Automation\Runtime\Action\DryRunActionDispatcher;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionBudget;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionException;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionContext;
use YTassy\Tycms\CoreExts\Automation\Runtime\ExecutionJournal;
use YTassy\Tycms\CoreExts\Automation\Runtime\RuntimeServices;
use YTassy\Tycms\CoreExts\Core\Plugin;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class DryRunActionDispatcherTest extends FrameworkTestCase
{
    #[Test]
    public function readsAreDelegatedToTheRealDispatcher(): void
    {
        $app = $this->createApplication();
        $registry = new AutomationActionRegistry($app);
        $registry->registerProvider('plg_core', new Plugin($app));
        $inner = new RecordingDryRunDispatcher();
        $dispatcher = new DryRunActionDispatcher($inner, $registry);

        $result = $dispatcher->dispatch('core::getUser', [1], $this->span(), $this->services());

        self::assertSame(['delegated' => 'core::getUser'], $result);
        self::assertSame(['core::getUser'], $inner->calls);
    }

    #[Test]
    public function writesAreRejectedBeforeTheRealDispatcher(): void
    {
        $app = $this->createApplication();
        $registry = new AutomationActionRegistry($app);
        $registry->registerProvider('plg_core', new Plugin($app));
        $inner = new RecordingDryRunDispatcher();
        $dispatcher = new DryRunActionDispatcher($inner, $registry);

        try {
            $dispatcher->dispatch(
                'core::addRole',
                [1, 2, 3],
                $this->span(),
                $this->services(),
            );
            self::fail('Une action d’écriture doit être refusée en simulation.');
        } catch (ExecutionException $exception) {
            self::assertSame(DiagnosticCode::ACTION_UNAVAILABLE, $exception->diagnostic->code);
        }

        self::assertSame([], $inner->calls);
    }

    #[Test]
    public function externalEffectsAreRejectedBeforeTheRealDispatcher(): void
    {
        $app = $this->createApplication();
        $registry = new AutomationActionRegistry($app);
        $registry->registerProvider('plg_core', new Plugin($app));
        $inner = new RecordingDryRunDispatcher();
        $dispatcher = new DryRunActionDispatcher($inner, $registry);

        try {
            $dispatcher->dispatch(
                'core::sendMailTemplate',
                ['template', 'to@example.test', []],
                $this->span(),
                $this->services(),
            );
            self::fail('Un effet externe doit être refusé en simulation.');
        } catch (ExecutionException $exception) {
            self::assertSame(DiagnosticCode::ACTION_UNAVAILABLE, $exception->diagnostic->code);
        }

        self::assertSame([], $inner->calls);
    }

    private function services(): RuntimeServices
    {
        $limits = new Limits();
        $context = ExecutionContext::create(
            ['team' => 3],
            ['id' => 42, 'type' => 'user'],
            ['id' => 7, 'timezone' => 'UTC'],
            ['id' => 0, 'scenarioId' => 1, 'versionId' => 0, 'trigger' => 'dry-run'],
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

final class RecordingDryRunDispatcher implements ActionDispatcher
{
    /** @var list<string> */
    public array $calls = [];

    public function dispatch(
        string $identifier,
        array $arguments,
        SourceSpan $span,
        RuntimeServices $services,
    ): mixed {
        $this->calls[] = $identifier;

        return ['delegated' => $identifier];
    }
}
