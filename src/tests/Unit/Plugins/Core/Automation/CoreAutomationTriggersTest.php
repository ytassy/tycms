<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationPrincipalMode;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationTriggerRegistry;
use YTassy\Tycms\CoreExts\Core\Automation\CoreAutomationTriggers;
use YTassy\Tycms\CoreExts\Core\Plugin;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class CoreAutomationTriggersTest extends FrameworkTestCase
{
    #[Test]
    public function coreDeclaresTheUserCreatedTrigger(): void
    {
        $definitions = CoreAutomationTriggers::definitions();

        self::assertCount(2, $definitions);
        self::assertSame('core.user.created', $definitions[0]->id);
        self::assertSame(AutomationPrincipalMode::TRIGGER, $definitions[0]->defaultPrincipalMode);
        self::assertCount(5, $definitions[0]->payloadFields);
        self::assertSame('user.email', $definitions[0]->payloadFields[4]->name);
        self::assertSame('ada@example.test', $definitions[0]->payloadExample['user']['email']);

        self::assertSame('core.page.button.clicked', $definitions[1]->id);
        self::assertSame('button.id', $definitions[1]->payloadFields[1]->name);
        self::assertSame('user', $definitions[1]->payloadExample['actor']['type']);
    }

    #[Test]
    public function corePluginRegistersItsTriggersUnderItsOwnership(): void
    {
        $app = $this->createApplication();
        $registry = new AutomationTriggerRegistry($app);
        $registry->registerProvider('plg_core', new Plugin($app));

        self::assertTrue($registry->has('core.user.created'));
        self::assertTrue($registry->has('core.page.button.clicked'));
        self::assertSame('plg_core', $registry->ownerOf('core.user.created'));
        self::assertCount(2, $registry->all());
    }
}
