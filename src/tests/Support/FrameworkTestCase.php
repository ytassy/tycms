<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Support;

use PHPUnit\Framework\TestCase;
use YTassy\Tycms\Application;

/**
 * Base class for tests that need an Application instance without booting it.
 *
 * Do not call Application::initialise() from unit tests: it starts the database,
 * migrations, extensions and session. Integration tests will receive their own
 * isolated application factory later.
 */
abstract class FrameworkTestCase extends TestCase
{
    protected function createApplication(): Application
    {
        $configuration = new \Configuration();
        $configuration->debug = true;
        $configuration->dev = true;
        $configuration->session_handler = 'php';
        $configuration->fonts_root = sys_get_temp_dir() . '/tycms-unit-fonts-' . bin2hex(random_bytes(8));

        return new Application($configuration);
    }
}
