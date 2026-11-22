<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationEventBuffer;
use YTassy\Tycms\CoreExts\Core\Automation\CoreAutomationTriggers;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class AutomationEventBufferTest extends FrameworkTestCase
{
    #[Test]
    public function emitIsIgnoredWhenAutomationIsDisabled(): void
    {
        $buffer = new AutomationEventBuffer($this->createApplication());

        self::assertNull($buffer->emit(
            1,
            CoreAutomationTriggers::USER_CREATED,
            ['user' => ['id' => 1]],
        ));
    }

    #[Test]
    public function emitRejectsMalformedIdentifiersBeforePersistence(): void
    {
        $buffer = new AutomationEventBuffer($this->enabledApplication());

        self::assertNull($buffer->emit(0, 'core.user.created', []));
        self::assertNull($buffer->emit(1, 'Not A Trigger', []));
    }

    #[Test]
    public function emitRequiresAMapPayload(): void
    {
        $buffer = new AutomationEventBuffer($this->enabledApplication());

        self::assertNull($buffer->emit(1, 'core.user.created', ['first', 'second']));
    }

    #[Test]
    public function emitStopsAChainAtTheMaximumDepth(): void
    {
        $buffer = new AutomationEventBuffer($this->enabledApplication());
        $buffer->enterEventContext(null, AutomationEventBuffer::MAX_DEPTH, null);

        try {
            self::assertNull($buffer->emit(1, 'core.user.created', []));
        } finally {
            $buffer->leaveEventContext();
        }
    }

    /** Application factice : l'extension est chargée, sans base de données. */
    private function enabledApplication(): Application
    {
        return new class (new \Configuration()) extends Application {
            public function getExtensions(): array
            {
                return ['plg_automation' => new \stdClass()];
            }
        };
    }
}
