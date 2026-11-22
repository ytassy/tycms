<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\AutomationActionRegistry;
use YTassy\Tycms\CoreExts\Automation\Language\Lexer;
use YTassy\Tycms\CoreExts\Automation\Language\Parser;
use YTassy\Tycms\CoreExts\Automation\Runtime\Action\ActionReferenceCollector;
use YTassy\Tycms\CoreExts\Core\Plugin;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class ActionReferenceCollectorTest extends FrameworkTestCase
{
    #[Test]
    public function collectsOnlyRegisteredActionsFromNestedExpressions(): void
    {
        $app = $this->createApplication();
        $registry = new AutomationActionRegistry($app);
        $registry->registerProvider('plg_core', new Plugin($app));

        $parsed = (new Parser(new Lexer()))->parse(<<<'TXT'
function main() {
    $name = str::upper("x");
    $user = core::getUser(1);
    if ($user->blocked) {
        core::sendMail("template", "a@b.test", ["name" => $name]);
    }
    return $user;
}
TXT);

        self::assertNotNull($parsed->program);
        self::assertSame([], $parsed->diagnostics);

        $collector = new ActionReferenceCollector($registry);

        self::assertSame(
            ['core::getUser', 'core::sendMail'],
            $collector->collect($parsed->program),
        );
    }

    #[Test]
    public function ignoresBuiltinsAndUserFunctions(): void
    {
        $app = $this->createApplication();
        $registry = new AutomationActionRegistry($app);
        $parsed = (new Parser(new Lexer()))->parse(<<<'TXT'
function label($value) {
    return str::upper($value);
}

function main() {
    return label("x");
}
TXT);

        self::assertNotNull($parsed->program);

        self::assertSame([], (new ActionReferenceCollector($registry))->collect($parsed->program));
    }
}
