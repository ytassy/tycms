<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\EmailTemplateHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\EmailTemplateRegistry;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Mail\EmailTemplateDefinition;
use YTassy\Tycms\CoreExts\Core\WF\WFEmailTemplate;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

final class EmailTemplateIntegrationTest extends TestCase
{
    public function testLockedDefinitionIgnoresStoredOverrideAndAnonymousCannotReadAdminDetail(): void
    {
        $environment = TestEnvironment::load();
        $database = new TestDatabase($environment);
        TestFixtures::assertPrepared($database);
        $application = new Application($environment->configuration());
        $application->initialiseOrThrow(new Request(host: TestFixtures::SITE_HOST));

        $registry = $application->getOrAddSharedExtensionsClass(EmailTemplateRegistry::class);
        $registry->register('plg_test', new EmailTemplateDefinition(
            id: 'locked_mail', label: 'Verrouillé', description: '',
            variants: ['fr' => ['subject' => 'Code', 'bodyHtml' => '<p>Code</p>']],
        ));
        $helper = $application->getOrAddSharedExtensionsClass(EmailTemplateHelper::class);
        $key = 'plg_test__locked_mail';

        try {
            $helper->save(2, $key, 'fr', 'Base', '<p>Base</p>', null);
            self::assertSame('code', $helper->describe($key, 2, 'fr')['source']);
            self::assertSame('code_fr_fallback', $helper->describe($key, 2, 'en')['source']);

            $workflow = $application->getOrAddSharedExtensionsClass(WFEmailTemplate::class);
            $this->expectException(ExceptionResponse::class);
            $workflow->describe(2, 'fr', $key);
        } finally {
            $helper->restore(2, $key, 'fr');
        }
    }

    public function testInheritedAndLocalCompleteOverrides(): void
    {
        $environment = TestEnvironment::load();
        $database = new TestDatabase($environment);
        TestFixtures::assertPrepared($database);

        $application = new Application($environment->configuration());
        $application->initialiseOrThrow(new Request(host: TestFixtures::SITE_HOST));
        $helper = $application->getOrAddSharedExtensionsClass(EmailTemplateHelper::class);
        $key = 'plg_core__registration_welcome';

        // Le nettoyage final porte seulement sur les deux lignes de ce scénario.
        try {
            $helper->restore(1, $key, 'fr');
            $helper->restore(2, $key, 'fr');
            self::assertSame('code', $helper->describe($key, 2, 'fr')['source']);

            $helper->save(1, $key, 'fr', 'Racine %space_title%', '<p>Bienvenue %first_name%</p>', null);
            $inherited = $helper->describe($key, 2, 'fr');
            self::assertSame('parent', $inherited['source']);
            self::assertSame('Racine %space_title%', $inherited['subject']);

            $helper->save(2, $key, 'fr', 'Site %space_title%', '<p>Salut %first_name%</p>', null);
            $local = $helper->describe($key, 2, 'fr');
            self::assertSame('space', $local['source']);
            self::assertSame('Site %space_title%', $local['subject']);
            self::assertStringContainsString('Salut', $local['bodyHtml']);

            $helper->restore(2, $key, 'fr');
            self::assertSame('parent', $helper->describe($key, 2, 'fr')['source']);
        } finally {
            $helper->restore(2, $key, 'fr');
            $helper->restore(1, $key, 'fr');
        }
    }
}
