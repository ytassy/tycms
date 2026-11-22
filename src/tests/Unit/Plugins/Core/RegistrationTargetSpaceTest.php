<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use ReflectionProperty;
use RuntimeException;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Space\SpaceHelper;
use YTassy\Tycms\CoreExts\Core\Helper\UserRegistrationHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class RegistrationTargetSpaceTest extends FrameworkTestCase
{
    public function testEmptyTargetKeepsTheSourceSpace(): void
    {
        $app = $this->createApplication();
        $source = new Space($app);
        $source->id = 2;
        $helper = new UserRegistrationHelper($app);

        foreach ([[], ['registration_target_space_id' => null],
            ['registration_target_space_id' => ''], ['registration_target_space_id' => 0]] as $params) {
            $source->params = (object) $params;
            self::assertSame($source, $helper->getRegistrationTargetSpace($source));
        }
    }

    public function testTargetIsResolvedOnceEvenIfItTargetsAnotherSpace(): void
    {
        $app = $this->createApplication();
        $source = new Space($app);
        $source->id = 2;
        $source->params = (object) ['registration_target_space_id' => 1];
        $target = new Space($app);
        $target->id = 1;
        $target->params = (object) ['registration_target_space_id' => 2];
        $spaces = $this->createStub(SpaceHelper::class);
        $spaces->method('getAllSpacesByIds')->willReturn([1 => $target, 2 => $source]);
        new ReflectionProperty(Application::class, 'sharedExtensionsClasses')
            ->setValue($app, [SpaceHelper::class => $spaces]);

        $helper = new UserRegistrationHelper($app);
        self::assertSame($target, $helper->getRegistrationTargetSpace($source));
        $source->params = (object) ['registration_target_space_id' => 2];
        self::assertSame($source, $helper->getRegistrationTargetSpace($source));
    }

    public function testInvalidTargetCannotSilentlyMoveTheAccountBackToTheSource(): void
    {
        $app = $this->createApplication();
        $spaces = $this->createStub(SpaceHelper::class);
        $spaces->method('getAllSpacesByIds')->willReturn([]);
        new ReflectionProperty(Application::class, 'sharedExtensionsClasses')
            ->setValue($app, [SpaceHelper::class => $spaces]);
        $source = new Space($app);
        $source->id = 2;
        $helper = new UserRegistrationHelper($app);

        foreach ([99999, -1, '1', true, []] as $invalidTarget) {
            $source->params = (object) ['registration_target_space_id' => $invalidTarget];
            try {
                $helper->getRegistrationTargetSpace($source);
                self::fail('Une cible invalide doit interrompre l’inscription.');
            } catch (RuntimeException $exception) {
                self::assertSame('L’espace principal configuré pour l’inscription est indisponible.', $exception->getMessage());
            }
        }
    }
}
