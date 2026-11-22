<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use YTassy\Tycms\CoreExts\Core\Helper\SsoProvider\FormsHelper;
use YTassy\Tycms\CoreExts\Core\Models\SsoProvider;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class SsoProviderFormTest extends FrameworkTestCase
{
    public function testFormLimitsSpaceOptionsAndNeverPrefillsTheSecret(): void
    {
        $app = $this->createApplication();
        $helper = new FormsHelper($app);
        $provider = new SsoProvider($app);
        $provider->id = 7;
        $provider->space_id = 2;
        $provider->protocol = 'oidc';
        $form = $helper->getSsoProviderForm($provider, [
            ['value' => 2, 'label' => 'Site (#2)'],
        ]);
        $ria = $form->getDefForRia(['space_id' => 2, 'protocol' => 'oidc']);
        self::assertSame('', $ria['initialValues']['client_secret']);
        self::assertStringNotContainsString('secret_data', json_encode($ria, JSON_THROW_ON_ERROR));
        $values = $form->getDefaultValues();
        self::assertSame('', $values['client_secret']);
        self::assertSame('keep', $values['secret_action']);

        $values['space_id'] = 2;
        $values['protocol'] = 'oidc';
        $values['name'] = 'Google';
        self::assertTrue($form->validateSubmittedValues((object) $values)->isValid());

        $values['code'] = 'google';
        self::assertFalse($form->validateSubmittedValues((object) $values)->isValid());
        unset($values['code']);

        $values['space_id'] = 3;
        self::assertFalse($form->validateSubmittedValues((object) $values)->isValid());
        $values['space_id'] = 2;
        $values['secret_data'] = 'forged';
        self::assertFalse($form->validateSubmittedValues((object) $values)->isValid());
    }
}
