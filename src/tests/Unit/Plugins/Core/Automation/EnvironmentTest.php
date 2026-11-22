<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Automation\Runtime\Environment\Environment;
use YTassy\Tycms\CoreExts\Automation\Runtime\Environment\ReadOnlyVariableException;
use YTassy\Tycms\CoreExts\Automation\Runtime\Environment\UndefinedVariableException;

final class EnvironmentTest extends TestCase
{
    #[Test]
    public function newVariableStaysInTheCurrentBlock(): void
    {
        $root = new Environment();
        $root->defineReadOnly('input', []);

        $block = new Environment($root);
        $block->assign('local', 1);

        self::assertTrue($block->has('local'));
        self::assertFalse($root->has('local'));

        $sibling = new Environment($root);
        self::assertFalse($sibling->has('local'));
    }

    #[Test]
    public function assignmentUpdatesTheNearestExistingVariable(): void
    {
        $root = new Environment();
        $root->define('counter', 1);

        $block = new Environment($root);
        $block->assign('counter', 2);

        self::assertSame(2, $root->get('counter'));
    }

    #[Test]
    public function readonlyContextVariableCannotBeAssigned(): void
    {
        $root = new Environment();
        $root->defineReadOnly('input', []);

        $this->expectException(ReadOnlyVariableException::class);
        $root->assign('input', ['bad' => true]);
    }

    #[Test]
    public function undefinedVariableIsRejected(): void
    {
        $this->expectException(UndefinedVariableException::class);
        (new Environment())->get('missing');
    }
}
