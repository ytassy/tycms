<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Migration\Mig_1_0_0_p3;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class MainTemplateInitialConfigTest extends FrameworkTestCase
{
    public function testInitialMigrationInsertsRootTemplateConfiguration(): void
    {
        $app = $this->createApplication();
        $database = $this->createMock(Database::class);
        $database->expects(self::once())->method('executeMultiple')->willReturn(1);
        $database->expects(self::once())->method('execute')->with(
            'INSERT INTO config (space_id, extension, params) VALUES (%, %, %)',
            self::callback(static function (array $values): bool {
                $config = json_decode($values[2], true, 512, JSON_THROW_ON_ERROR);
                return $values[0] === 1 && $values[1] === 'tpl_main'
                    && $config['main_menu_id'] === 1 && $config['footer_page_id'] === 3
                    && $config['logo'] === ASSETS_CORE_PATH . '/tpl_main/images/my_logo.webp'
                    && $config['email_footer_text'] === '© {space} {year}'
                    && $config['theme_heading_font_family'] === 'bona_nova'
                    && $config['theme_custom_compiled_css'] === '';
            }),
        )->willReturn(1);
        (new ReflectionProperty(Application::class, 'database'))->setValue($app, $database);

        (new Mig_1_0_0_p3($app))->run();
    }
}
