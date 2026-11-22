<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Automation\Language\Registry\ContextVariableCatalog;

final class ContextVariableCatalogTest extends TestCase
{
    #[Test]
    public function baseContextDocumentsTheStableVariables(): void
    {
        $base = ContextVariableCatalog::base();

        self::assertSame(['$principal', '$space', '$run'], array_column($base, 'name'));

        foreach ($base as $variable) {
            self::assertTrue($variable['readonly']);
            self::assertNotEmpty($variable['fields']);
            self::assertIsArray($variable['example']);

            foreach ($variable['fields'] as $field) {
                self::assertNotSame('', $field['name']);
                self::assertNotSame('', $field['type']);
                self::assertNotSame('', $field['description']);
            }
        }

        self::assertContains('type', array_column($base[0]['fields'], 'name'));
        self::assertContains('timezone', array_column($base[1]['fields'], 'name'));
        self::assertContains('startedAt', array_column($base[2]['fields'], 'name'));
    }

    #[Test]
    public function inputIsAssembledFromTheTriggerPayload(): void
    {
        $input = ContextVariableCatalog::input(
            [[
                'name' => 'user.email',
                'type' => 'string',
                'required' => true,
                'description' => 'Adresse e-mail.',
            ]],
            ['user' => ['email' => 'ada@example.test']],
        );

        self::assertSame('$input', $input['name']);
        self::assertTrue($input['readonly']);
        self::assertSame('user.email', $input['fields'][0]['name']);
        self::assertSame('ada@example.test', $input['example']['user']['email']);
    }
}
