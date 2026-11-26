<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Lms;

use YTassy\Tycms\Plugins\Lms\Plugin;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class LmsConfigViewTest extends FrameworkTestCase
{
    public function testTheExtensionDeclaresBothTheFormAndItsOwnView(): void
    {
        $plugin = new Plugin($this->createApplication());

        // La vue est compilée dans le bundle de l’extension, pas dans le Core.
        self::assertSame('lms/config', $plugin->getConfigViewComponentName());

        $form = $plugin->coreRegisterConfigForm();
        self::assertNotNull($form);
        self::assertSame('lms.config', $form->getDefinition()['id']);
        self::assertSame(
            ['courses_per_page', 'show_course_count'],
            array_column($form->getDefinition()['sections'][0]['fields'], 'name'),
        );
    }
}
