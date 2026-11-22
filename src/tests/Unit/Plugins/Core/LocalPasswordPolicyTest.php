<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\Security\LocalPasswordPolicy;

final class LocalPasswordPolicyTest extends TestCase
{
    public function testThePolicyChecksTheBytesActuallyUsedByBcrypt(): void
    {
        self::assertTrue(LocalPasswordPolicy::acceptsNewPassword(str_repeat('a', 12)));
        self::assertTrue(LocalPasswordPolicy::acceptsNewPassword(str_repeat('a', 72)));
        self::assertTrue(LocalPasswordPolicy::acceptsNewPassword(str_repeat('é', 36)));
        foreach ([null, [], '', str_repeat('a', 11), str_repeat('a', 73), str_repeat('é', 37), "mot-de-passe\0invalide"] as $value) {
            self::assertFalse(LocalPasswordPolicy::acceptsNewPassword($value));
        }
    }
}
