<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Automation;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Core\Helper\Page\PageButtonHelper;
use YTassy\Tycms\CoreExts\Core\PageComponents\Button;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;
use YTassy\Tycms\Tests\Support\PageFixtures;

final class PageButtonMessageTest extends FrameworkTestCase
{
    private function helper(): PageButtonHelper
    {
        $app = $this->createApplication();
        PageFixtures::initialize($app);

        return new PageButtonHelper($app);
    }

    #[Test]
    public function defaultModeUsesTheGenericMessage(): void
    {
        self::assertSame(
            PageButtonHelper::DEFAULT_SUCCESS_MESSAGE,
            $this->helper()->successMessage((object) [], 'fr', 1),
        );

        self::assertSame(
            PageButtonHelper::DEFAULT_SUCCESS_MESSAGE,
            $this->helper()->successMessage(
                (object) ['success_message_mode' => 'default', 'success_message' => 'Ignoré'],
                'fr',
                1,
            ),
        );
    }

    #[Test]
    public function customModeSelectsTheCurrentLocale(): void
    {
        $helper = $this->helper();
        $props = (object) [
            'success_message_mode' => 'custom',
            'success_message' => (object) ['fr' => 'Merci', 'en' => 'Thanks'],
        ];

        self::assertSame('Merci', $helper->successMessage($props, 'fr', 1));
        self::assertSame('Thanks', $helper->successMessage($props, 'en', 1));
        self::assertSame('Merci', $helper->successMessage(
            (object) ['success_message_mode' => 'custom', 'success_message' => 'Merci'],
            'fr',
            1,
        ));
    }

    #[Test]
    public function customModeFallsBackWhenTheLocaleIsEmpty(): void
    {
        $props = (object) [
            'success_message_mode' => 'custom',
            'success_message' => (object) ['fr' => 'Merci'],
        ];

        self::assertSame(
            PageButtonHelper::DEFAULT_SUCCESS_MESSAGE,
            $this->helper()->successMessage($props, 'en', 1),
        );
    }

    #[Test]
    public function noneModeSuppressesTheMessage(): void
    {
        self::assertNull(
            $this->helper()->successMessage((object) ['success_message_mode' => 'none'], 'fr', 1),
        );
    }

    #[Test]
    public function resultModeUsesAStringOutput(): void
    {
        $helper = $this->helper();
        $props = (object) ['success_message_mode' => 'result'];

        self::assertSame(
            'Rendez-vous confirmé.',
            $helper->successMessage($props, 'fr', 1, '  Rendez-vous confirmé.  '),
        );
        self::assertSame(
            PageButtonHelper::DEFAULT_SUCCESS_MESSAGE,
            $helper->successMessage($props, 'fr', 1, null),
        );
        self::assertSame(
            PageButtonHelper::DEFAULT_SUCCESS_MESSAGE,
            $helper->successMessage($props, 'fr', 1, ['ok' => true]),
        );
        self::assertSame(
            PageButtonHelper::DEFAULT_SUCCESS_MESSAGE,
            $helper->successMessage($props, 'fr', 1, '   '),
        );
    }

    #[Test]
    public function resultModeTruncatesLongOutput(): void
    {
        $displayed = $this->helper()->successMessage(
            (object) ['success_message_mode' => 'result'],
            'fr',
            1,
            str_repeat('a', 501),
        );

        self::assertSame(str_repeat('a', 500) . '…', $displayed);
        self::assertSame(501, mb_strlen((string) $displayed));
    }

    #[Test]
    public function invalidModeFallsBackToDefault(): void
    {
        $helper = $this->helper();

        self::assertSame(
            'default',
            $helper->successMessageMode((object) ['success_message_mode' => 'unknown']),
        );
        self::assertSame(
            PageButtonHelper::DEFAULT_SUCCESS_MESSAGE,
            $helper->successMessage((object) ['success_message_mode' => 'unknown'], 'fr', 1),
        );
    }

    #[Test]
    public function buttonDeclaresTheLocalizedMessageFields(): void
    {
        $fields = array_column(Button::getEditorFields(), null, 'name');
        $schema = Button::getPropsSchema();

        self::assertTrue($fields['success_message']['localized']);
        self::assertSame(300, $fields['success_message']['maxLength']);
        self::assertArrayHasKey('success_message', $schema['properties']);
        self::assertArrayHasKey('success_message_mode', $schema['properties']);
        self::assertArrayHasKey('reload_on_close', $schema['properties']);
    }

    #[Test]
    public function onlyAutomationButtonsMountThePublicVueComponent(): void
    {
        self::assertSame(
            'core/automation-button',
            Button::getPublicVueComponentNameFor((object) [
                'props' => (object) ['button_action' => 'automation'],
            ]),
        );
        self::assertNull(Button::getPublicVueComponentNameFor((object) [
            'props' => (object) ['button_action' => 'link'],
        ]));
        self::assertNull(Button::getPublicVueComponentNameFor((object) [
            'props' => (object) [],
        ]));
    }

    #[Test]
    public function scenarioResponseSelectsTheModalLevel(): void
    {
        $helper = $this->helper();
        $props = (object) ['success_message_mode' => 'result'];

        self::assertSame(
            ['message' => 'Confirmé.', 'level' => 'success'],
            $helper->feedback($props, 'fr', 1, [
                'error' => false,
                'is_message' => true,
                'message_level' => 'SUCCESS',
                'message' => 'Confirmé.',
            ]),
        );
        self::assertSame(
            ['message' => 'Info.', 'level' => 'info'],
            $helper->feedback($props, 'fr', 1, ['message_level' => 'INFO', 'message' => 'Info.']),
        );
        self::assertSame(
            ['message' => 'Attention.', 'level' => 'warning'],
            $helper->feedback($props, 'fr', 1, ['message_level' => 'WARNING', 'message' => 'Attention.']),
        );
        self::assertSame(
            ['message' => 'Alerte.', 'level' => 'danger'],
            $helper->feedback($props, 'fr', 1, ['message_level' => 'DANGER', 'message' => 'Alerte.']),
        );
        self::assertSame(
            ['message' => 'Erreur.', 'level' => 'error'],
            $helper->feedback($props, 'fr', 1, ['error' => true, 'message_level' => 'ERROR', 'message' => 'Erreur.']),
        );
    }

    #[Test]
    public function stringOutputIsAConfirmation(): void
    {
        self::assertSame(
            ['message' => 'Merci.', 'level' => 'success'],
            $this->helper()->feedback(
                (object) ['success_message_mode' => 'result'],
                'fr',
                1,
                'Merci.',
            ),
        );
    }

    #[Test]
    public function errorFlagOverridesASuccessLevel(): void
    {
        self::assertSame(
            ['message' => 'Oups.', 'level' => 'error'],
            $this->helper()->feedback(
                (object) ['success_message_mode' => 'result'],
                'fr',
                1,
                ['error' => true, 'message_level' => 'SUCCESS', 'message' => 'Oups.'],
            ),
        );
    }
}
