<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Security;

use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminController;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Models\Config;
use YTassy\Tycms\CoreExts\Security\SecurityPolicy;
use YTassy\Tycms\CoreExts\Security\Helper\SecurityPolicyHelper;
use YTassy\Tycms\CoreExts\Core\PageComponents\Iframe;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageEditContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageNormalizationContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Page\PageDataMode;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Routing\Route;
use YTassy\Tycms\Session\Session;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;
use YTassy\Tycms\Tests\Support\PageFixtures;

final class SecurityPolicyHelperTest extends FrameworkTestCase
{
    public function testPolicyCacheIsPartitionedBySpaceAndCanBeInvalidated(): void
    {
        $app = $this->createApplication();
        $db = $this->createMock(Database::class);
        $db->expects(self::exactly(3))->method('selectOrmOneOrNull')->willReturnCallback(
            static function ($class, string $sql, array $params) use ($app): Config {
                self::assertSame(Config::class, $class);
                self::assertSame('plg_security', $params[0]);
                self::assertStringContainsString('space_id = %', $sql);
                $config = new Config($app);
                $config->params = (object) [...\YTassy\Tycms\CoreExts\Security\Configuration::defaults(), 'iframe_mode' => 'allowlist',
                    'iframe_origins' => $params[1] === 1 ? 'https://docs.google.com' : 'https://player.vimeo.com'];
                return $config;
            },
        );
        (new ReflectionProperty(Application::class, 'database'))->setValue($app, $db);
        $helper = new SecurityPolicyHelper($app);
        self::assertTrue($helper->allowsFrame(1, 'https://docs.google.com/embed'));
        self::assertFalse($helper->allowsFrame(2, 'https://docs.google.com/embed'));
        self::assertSame($helper->forSpace(1), $helper->forSpace(1));
        $helper->forget(1);
        self::assertSame(['https://docs.google.com'], $helper->forSpace(1)->sources('iframe'));
    }

    public function testIframeUsesTheRealPageSpaceForFormValidationAndRendering(): void
    {
        $app = PageFixtures::application();
        $policy = new class($app) extends SecurityPolicyHelper {
            public function forSpace(int $spaceId): SecurityPolicy
            {
                return new SecurityPolicy([...SecurityPolicy::defaults(), 'iframe_mode' => 'allowlist',
                    'iframe_origins' => $spaceId === 7 ? 'https://docs.google.com' : 'https://player.vimeo.com']);
            }
        };
        $property = new ReflectionProperty(Application::class, 'sharedExtensionsClasses');
        $property->setValue($app, [...$property->getValue($app), SecurityPolicyHelper::class => $policy]);
        $component = new Iframe($app);
        $props = (object) [...Iframe::getDefaultData(), 'src' => 'https://docs.google.com/presentation/embed'];
        self::assertStringContainsString('https://docs.google.com', $component->render((object) ['props' => $props], PageFixtures::renderContext(7)));
        self::assertSame('', $component->render((object) ['props' => $props], PageFixtures::renderContext(8)));
        $form = $component->getGeneralForm(new PageEditContext(8))->getDefinition();
        self::assertStringContainsString('https://player.vimeo.com', json_encode($form, JSON_UNESCAPED_SLASHES));
        $component->validateData($props, new PageNormalizationContext(7, PageDataMode::INPUT));
        $this->expectException(ExceptionResponse::class);
        $component->validateData($props, new PageNormalizationContext(8, PageDataMode::INPUT));
    }

    public function testAdministrationOnlyCombinesPoliciesOfReadablePageSpaces(): void
    {
        $app = $this->createApplication();
        $session = $this->createStub(Session::class);
        $session->method('getSpaceId')->willReturn(1);
        (new ReflectionProperty(Application::class, 'session'))->setValue($app, $session);
        $route = new Route('AdministratorHome', new AdminController($app), 'home', false, [], [], [], false, false, false, false, []);
        (new ReflectionProperty(Application::class, 'route'))->setValue($app, $route);
        $acl = $this->createMock(AclHelper::class);
        $acl->expects(self::once())->method('getSpacesHavingGlobalPermission')
            ->with([Permissions::PAGE_READ, Permissions::PAGE_CRUD])->willReturn([2]);
        (new ReflectionProperty(Application::class, 'sharedExtensionsClasses'))->setValue($app, [AclHelper::class => $acl]);
        $helper = new class($app) extends SecurityPolicyHelper {
            public function forSpace(int $spaceId): SecurityPolicy
            {
                return new SecurityPolicy([...SecurityPolicy::defaults(), 'iframe_mode' => 'allowlist',
                    'iframe_origins' => "https://space$spaceId.test"]);
            }
        };
        self::assertSame(['https://space1.test'], $helper->responseSources(false)['iframe']);
        self::assertSame(['https://space1.test', 'https://space2.test', 'https://embed.diagrams.net'], $helper->responseSources(true)['iframe']);
    }
}
