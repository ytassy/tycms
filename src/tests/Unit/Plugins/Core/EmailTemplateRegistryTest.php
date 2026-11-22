<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use YTassy\Tycms\CoreExts\Core\Helper\Mail\EmailTemplateRegistry;
use YTassy\Tycms\CoreExts\Core\Plugin;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class EmailTemplateRegistryTest extends FrameworkTestCase
{
    public function testCoreDefinitionsRegisterWithTheirOwner(): void
    {
        $app = $this->createApplication();
        $registry = new EmailTemplateRegistry($app);
        $plugin = new Plugin($app);

        foreach ($plugin->emailTemplates() as $definition) {
            $registry->register('plg_core', $definition);
        }

        self::assertCount(4, $registry->all());
        self::assertTrue($registry->get('plg_core__password_reset')['definition']->customizable);
        self::assertSame('Bienvenue après inscription',
            $registry->get('plg_core__registration_welcome')['definition']->label);
        self::assertContains('initial_password', array_map(
            static fn ($variable): string => $variable->name,
            $registry->get('plg_core__admin_account_welcome')['definition']->variables,
        ));
    }
}
