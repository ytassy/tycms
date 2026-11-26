<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Integration;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\EmailTemplateHelper;
use YTassy\Tycms\CoreExts\Core\WF\WFEmailTemplate;
use YTassy\Tycms\Exceptions\ApplicationException;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

final class EmailTemplateCustomIntegrationTest extends TestCase
{
    /** @var list<string> */
    private array $createdKeys = [];

    private ?EmailTemplateHelper $helper = null;

    public function testCustomTemplateLifecycleAndInheritance(): void
    {
        $environment = TestEnvironment::load();
        $database = new TestDatabase($environment);
        TestFixtures::assertPrepared($database);
        $application = new Application($environment->configuration());
        $application->initialiseOrThrow(new Request(host: TestFixtures::SITE_HOST));
        $application->getSession()->setUser(1);

        $this->helper = $application->getOrAddSharedExtensionsClass(EmailTemplateHelper::class);
        $workflow = $application->getOrAddSharedExtensionsClass(WFEmailTemplate::class);

        try {
            // Création dans l'espace racine, avec deux variables et un contenu initial.
            $data = (object) [
                'label' => 'Bienvenue commande',
                'description' => 'Message envoyé après une commande.',
                'renderMode' => 'default',
                'locale' => 'fr',
                'subject' => 'Commande %order_id%',
                'bodyHtml' => '<p>Bonjour %first_name%, commande %order_id%.</p>',
                'variables' => [
                    (object) ['name' => 'first_name', 'example' => 'Alice'],
                    (object) ['name' => 'order_id', 'example' => 'CMD-1'],
                ],
            ];
            $created = $workflow->create(TestFixtures::ROOT_SPACE_ID, $data);
            self::assertSame('custom__bienvenue_commande', $created['key']);
            $this->createdKeys[] = $created['key'];
            $key = $created['key'];

            // Un libellé identique reçoit une clé suffixée.
            $second = $workflow->create(TestFixtures::ROOT_SPACE_ID, $data);
            self::assertSame('custom__bienvenue_commande_2', $second['key']);
            $this->createdKeys[] = $second['key'];
            $this->helper->deleteCustom(TestFixtures::ROOT_SPACE_ID, $second['key']);

            // L'espace propriétaire détient le contenu de base.
            $root = $this->helper->describe($key, TestFixtures::ROOT_SPACE_ID, 'fr');
            self::assertTrue($root['custom']);
            self::assertSame('custom', $root['owner']);
            self::assertSame(TestFixtures::ROOT_SPACE_ID, $root['ownerSpaceId']);
            self::assertSame('fr', $root['baseLocale']);
            self::assertSame('space', $root['source']);
            self::assertSame('Commande %order_id%', $root['subject']);

            // L'espace enfant hérite de la définition et du contenu de base du parent, sans copie.
            $child = $this->helper->describe($key, TestFixtures::SITE_SPACE_ID, 'fr');
            self::assertSame('parent', $child['source']);
            self::assertSame(TestFixtures::ROOT_SPACE_ID, $child['sourceSpaceId']);
            self::assertSame('Bienvenue commande', $child['label']);

            // Une langue sans contenu reprend le contenu de base.
            $english = $this->helper->describe($key, TestFixtures::SITE_SPACE_ID, 'en');
            self::assertSame('custom', $english['source']);
            self::assertSame('fr', $english['effectiveLocale']);

            // L'espace enfant peut adapter le contenu puis restaurer l'héritage.
            $this->helper->save(TestFixtures::SITE_SPACE_ID, $key, 'fr',
                'Commande enfant %order_id%', '<p>Enfant %first_name%</p>', 1);
            $local = $this->helper->describe($key, TestFixtures::SITE_SPACE_ID, 'fr');
            self::assertSame('space', $local['source']);
            self::assertSame('Commande enfant %order_id%', $local['subject']);
            $this->helper->restore(TestFixtures::SITE_SPACE_ID, $key, 'fr');
            self::assertSame('parent', $this->helper->describe($key, TestFixtures::SITE_SPACE_ID, 'fr')['source']);

            // Le contenu de base du propriétaire ne peut pas être retiré.
            $this->assertWarning(static fn () => $workflow->restore(TestFixtures::ROOT_SPACE_ID, (object) [
                'key' => $key, 'locale' => 'fr',
            ]));

            // Les métadonnées se gèrent uniquement depuis l'espace propriétaire.
            $this->assertWarning(static fn () => $workflow->updateMeta(TestFixtures::SITE_SPACE_ID, (object) [
                'key' => $key, 'label' => 'Depuis l’enfant', 'description' => '',
                'renderMode' => 'default', 'variables' => [],
            ]));

            // Retirer une variable encore utilisée par un contenu existant est refusé.
            $this->assertWarning(static fn () => $workflow->updateMeta(TestFixtures::ROOT_SPACE_ID, (object) [
                'key' => $key, 'label' => 'Commande', 'description' => '',
                'renderMode' => 'default',
                'variables' => [['name' => 'order_id', 'example' => 'CMD-1']],
            ]));

            $workflow->updateMeta(TestFixtures::ROOT_SPACE_ID, (object) [
                'key' => $key, 'label' => 'Commande confirmée', 'description' => '',
                'renderMode' => 'blank',
                'variables' => [
                    ['name' => 'first_name', 'example' => 'Alice'],
                    ['name' => 'order_id', 'example' => 'CMD-1'],
                ],
            ]);
            $updated = $this->helper->describe($key, TestFixtures::ROOT_SPACE_ID, 'fr');
            self::assertSame('Commande confirmée', $updated['label']);
            self::assertSame('blank', $updated['renderMode']);

            // La liste de l'espace enfant contient le modèle hérité.
            $entries = $workflow->entries(TestFixtures::SITE_SPACE_ID, 'fr')['entries'];
            $custom = array_values(array_filter(
                $entries,
                static fn (array $entry): bool => $entry['key'] === $key,
            ));
            self::assertCount(1, $custom);
            self::assertTrue($custom[0]['custom']);

            // Un modèle du code n'est ni modifiable ni supprimable comme un modèle personnalisé.
            $this->assertWarning(static fn () => $workflow->delete(TestFixtures::ROOT_SPACE_ID, (object) [
                'key' => 'plg_core__password_reset',
            ]));

            // La suppression retire la définition et les personnalisations descendantes.
            $this->helper->save(TestFixtures::SITE_SPACE_ID, $key, 'fr',
                'Commande enfant %order_id%', '<p>Enfant %first_name%</p>', 1);
            $this->assertWarning(static fn () => $workflow->delete(TestFixtures::SITE_SPACE_ID, (object) [
                'key' => $key,
            ]));
            $workflow->delete(TestFixtures::ROOT_SPACE_ID, (object) ['key' => $key]);
            $this->createdKeys = [];

            $db = $application->getDatabase();
            self::assertNull($db->selectOneOrNull(
                'SELECT 1 AS present FROM email_templates WHERE template_key = %', [$key],
            ));
            self::assertNull($db->selectOneOrNull(
                'SELECT 1 AS present FROM email_template_overrides WHERE template_key = %', [$key],
            ));

            $caught = null;
            try {
                $this->helper->resolveEntry($key, TestFixtures::ROOT_SPACE_ID);
            } catch (ApplicationException $exception) {
                $caught = $exception;
            }
            self::assertInstanceOf(ApplicationException::class, $caught);
        } finally {
            foreach ($this->createdKeys as $createdKey) {
                try {
                    $this->helper?->deleteCustom(TestFixtures::ROOT_SPACE_ID, $createdKey);
                } catch (\Throwable) {
                    // Nettoyage au mieux : le test a déjà échoué.
                }
            }
        }
    }

    public function testAnonymousCannotCreateCustomTemplate(): void
    {
        $environment = TestEnvironment::load();
        $database = new TestDatabase($environment);
        TestFixtures::assertPrepared($database);
        $application = new Application($environment->configuration());
        $application->initialiseOrThrow(new Request(host: TestFixtures::SITE_HOST));
        $workflow = $application->getOrAddSharedExtensionsClass(WFEmailTemplate::class);

        $this->expectException(ExceptionResponse::class);
        $workflow->create(TestFixtures::ROOT_SPACE_ID, (object) [
            'label' => 'Modèle anonyme',
            'description' => '',
            'renderMode' => 'default',
            'locale' => 'fr',
            'subject' => 'Sujet',
            'bodyHtml' => '<p>Contenu</p>',
            'variables' => [],
        ]);
    }

    public function testCustomTemplateRoutesAreRegisteredAsApiPostsWithTransaction(): void
    {
        $environment = TestEnvironment::load();
        $database = new TestDatabase($environment);
        TestFixtures::assertPrepared($database);
        $application = new Application($environment->configuration());
        $application->initialiseOrThrow(new Request(host: TestFixtures::SITE_HOST));
        $router = $application->getRouter();

        foreach (['create', 'update', 'delete'] as $action) {
            $route = $router->resolve(new Request(
                method: RequestMethod::POST,
                uri: '/administrator/api/email-templates/' . $action,
                host: TestFixtures::SITE_HOST,
            ));

            self::assertNotNull($route, $action);
            self::assertSame('administrator_api_email_templates_' . $action, $route->name);
            self::assertTrue($route->isApi);
            self::assertFalse($route->noTransaction);
        }
    }

    private function assertWarning(callable $call): void
    {
        try {
            $call();
        } catch (ExceptionResponse $exception) {
            self::assertInstanceOf(ExceptionResponse::class, $exception);
            return;
        }

        self::fail('Un refus contrôle était attendu.');
    }
}
