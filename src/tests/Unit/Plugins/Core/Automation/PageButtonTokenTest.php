<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageButtonToken;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class PageButtonTokenTest extends FrameworkTestCase
{
    #[Test]
    public function tokenBindsPageNodeAndCsrf(): void
    {
        $token = new PageButtonToken($this->createApplication());
        $signed = $token->sign(12, 'b_7f3a', 'csrf-value');

        self::assertTrue($token->verify(12, 'b_7f3a', 'csrf-value', $signed));
        self::assertFalse($token->verify(13, 'b_7f3a', 'csrf-value', $signed));
        self::assertFalse($token->verify(12, 'b_other', 'csrf-value', $signed));
        self::assertFalse($token->verify(12, 'b_7f3a', 'other-csrf', $signed));
        self::assertFalse($token->verify(12, 'b_7f3a', 'csrf-value', ''));
    }
}
