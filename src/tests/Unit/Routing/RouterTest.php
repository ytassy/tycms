<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Routing;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminFontController;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Request\RequestMethod;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerParam1;
use YTassy\Tycms\Routing\Annotation\Doc\VarType;
use YTassy\Tycms\Routing\Router;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class RouterTest extends FrameworkTestCase
{
    #[Test]
    public function structuredValuesNeverReachScalarValidators(): void
    {
        $router = new Router($this->createApplication());
        $resolve = new ReflectionMethod(Router::class, 'resolveInputsForRoute');

        foreach (VarType::cases() as $type) {
            foreach ([['22'], (object) ['value' => '22']] as $value) {
                $errors = [];
                $inputs = $resolve->invokeArgs($router, [new Request(body: ['value' => $value]),
                    [new ControllerParam1(name: 'value', required: true, varType: $type)], &$errors]);

                self::assertCount(1, $errors);
                self::assertArrayNotHasKey('value', $inputs);
            }
        }
    }

    #[Test]
    #[DataProvider('validTypedValues')]
    public function validScalarsAndSerializedJsonKeepTheirMeaning(VarType $type, mixed $value, mixed $expected): void
    {
        $resolve = new ReflectionMethod(Router::class, 'resolveInputsForRoute');
        $errors = [];
        $inputs = $resolve->invokeArgs(new Router($this->createApplication()), [
            new Request(body: ['value' => $value]),
            [new ControllerParam1(name: 'value', required: true, varType: $type)], &$errors,
        ]);

        self::assertSame([], $errors);
        self::assertEquals($expected, $inputs['value']);
    }

    public static function validTypedValues(): iterable
    {
        yield [VarType::INT, '22', 22];
        yield [VarType::INT, 22, 22];
        yield [VarType::FLOAT, 2.5, 2.5];
        yield [VarType::FLOAT, '2.5', 2.5];
        yield [VarType::STRING, 'texte', 'texte'];
        yield [VarType::JSON, '{"enabled":false,"items":[1,2]}', (object) ['enabled' => false, 'items' => [1, 2]]];
        yield [VarType::JSON_ARRAY, '[{"id":22}]', [['id' => 22]]];
    }

    #[Test]
    public function itAcceptsJsonAndFormBooleansWithoutConvertingFalseToEmptyText(): void
    {
        $router = new Router($this->createApplication());
        $resolveInputs = new ReflectionMethod(Router::class, 'resolveInputsForRoute');

        foreach ([true, false, 1, 0, '1', '0', 'true', 'false', 'TRUE', 'FALSE'] as $value) {
            $errors = [];
            $inputs = $resolveInputs->invokeArgs($router, [
                new Request(body: ['enabled' => $value]),
                [new ControllerParam1(name: 'enabled', required: true, varType: VarType::BOOL)],
                &$errors,
            ]);

            self::assertSame([], $errors);
            self::assertSame(in_array($value, [true, 1, '1', 'true', 'TRUE'], true), $inputs['enabled']);
        }
    }

    #[Test]
    public function itRejectsInvalidBooleanValuesWithoutImplicitCoercion(): void
    {
        $router = new Router($this->createApplication());
        $resolveInputs = new ReflectionMethod(Router::class, 'resolveInputsForRoute');

        foreach (['', 'yes', 'off', ' false ', 2, -1, 0.0, 1.0, [], (object) [], null] as $value) {
            $errors = [];
            $inputs = $resolveInputs->invokeArgs($router, [
                new Request(body: ['enabled' => $value]),
                [new ControllerParam1(name: 'enabled', required: true, varType: VarType::BOOL)],
                &$errors,
            ]);

            self::assertCount(1, $errors);
            self::assertArrayNotHasKey('enabled', $inputs);
        }
    }

    #[Test]
    public function fontActivationRouteAcceptsBothJsonBooleanStates(): void
    {
        $app = $this->createApplication();
        $router = new Router($app);
        (new ReflectionMethod(Router::class, 'registerController'))->invoke($router, new AdminFontController($app));

        foreach ([false, true] as $enabled) {
            $route = $router->resolve(new Request(
                method: RequestMethod::POST,
                uri: '/administrator/api/fonts/enabled',
                body: json_decode(json_encode(['id' => 'google_nunito', 'enabled' => $enabled]), true),
            ));

            self::assertNotNull($route);
            self::assertSame([], $route->errors);
            self::assertSame($enabled, $route->inputs['enabled']);
        }
    }

    #[Test]
    public function itAcceptsAnAbsentOptionalTypedInput(): void
    {
        $router = new Router($this->createApplication());
        $resolveInputs = new ReflectionMethod(Router::class, 'resolveInputsForRoute');
        $errors = [];

        /** @var array<string,mixed> $inputs */
        $inputs = $resolveInputs->invokeArgs($router, [
            new Request(),
            [new ControllerParam1(name: 'menuItemId', required: false, varType: VarType::INT)],
            &$errors,
        ]);

        self::assertSame(['menuItemId' => null], $inputs);
        self::assertSame([], $errors);
    }
}
