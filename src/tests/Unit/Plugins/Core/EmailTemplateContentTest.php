<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Mail\EmailTemplateVariable;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\EmailTemplateContent;
use YTassy\Tycms\CoreExts\Core\Mail\CoreEmailTemplates;
use YTassy\Tycms\Exceptions\ApplicationException;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class EmailTemplateContentTest extends FrameworkTestCase
{
    public function testRendersTextAndActionUrlWithoutReinterpretingValues(): void
    {
        $variables = [
            'name' => new EmailTemplateVariable('name'),
            'reset_url' => new EmailTemplateVariable('reset_url', 'https_url'),
        ];

        [$subject, $body] = EmailTemplateContent::render(
            'Bonjour %name%',
            '<p>Bonjour %name%, <a href="%reset_url%">ouvrir</a></p>',
            $variables,
            ['name' => '<Alice>%second%', 'reset_url' => 'https://example.test/reset?t=1&n=2'],
        );

        self::assertSame('Bonjour <Alice>%second%', $subject);
        self::assertStringContainsString('&lt;Alice&gt;%second%', $body);
        self::assertStringContainsString('href="https://example.test/reset?t=1&amp;n=2"', $body);
    }

    public function testResetUrlCanAppearInSubjectTextAndAllowedAttributes(): void
    {
        $variable = CoreEmailTemplates::all()[0]->variables[0];
        $url = 'https://example.test/reset?t=1&n=2';
        [$subject, $body] = EmailTemplateContent::render(
            'Lien : %reset_url%',
            '<p>%reset_url%</p><a href="%reset_url%" title="Lien : %reset_url%">%reset_url%</a>'
                . '<img src="/medias/site/photo.png" alt="%reset_url%">',
            ['reset_url' => $variable],
            ['reset_url' => $url],
        );

        self::assertSame('Lien : ' . $url, $subject);
        self::assertSame(5, substr_count($body, 'https://example.test/reset?t=1&amp;n=2'));
        self::assertStringNotContainsString('%reset_url%', $body);
    }

    public function testResetUrlDoesNotRequireAClickableLink(): void
    {
        $variable = CoreEmailTemplates::all()[0]->variables[0];
        $variables = ['reset_url' => $variable];
        $values = ['reset_url' => 'https://example.test/reset'];

        self::assertSame(['Lien', '<p>https://example.test/reset</p>'], EmailTemplateContent::render(
            'Lien', '<p>%reset_url%</p>', $variables, $values,
        ));
        self::assertSame(['https://example.test/reset', '<p>Bonjour</p>'], EmailTemplateContent::render(
            '%reset_url%', '<p>Bonjour</p>', $variables, $values,
        ));
    }

    public function testUrlStillRequiresHttpsAndCannotInjectHtml(): void
    {
        $variables = ['reset_url' => new EmailTemplateVariable('reset_url', 'https_url')];
        foreach (['javascript:alert(1)', 'http://example.test/reset', 'https://user:pass@example.test/reset'] as $url) {
            try {
                EmailTemplateContent::render('Lien', '<p>%reset_url%</p>', $variables, ['reset_url' => $url]);
                self::fail('Une URL non HTTPS ou contenant des identifiants doit être refusée.');
            } catch (ApplicationException) {
                self::addToAssertionCount(1);
            }
        }

        [, $body] = EmailTemplateContent::render(
            'Lien', '<a title="%reset_url%" href="%reset_url%">%reset_url%</a>', $variables,
            ['reset_url' => 'https://example.test/reset?x="><img/src=x/onerror=alert(1)>'],
        );
        self::assertStringContainsString('x=&quot;&gt;&lt;img/src=x/onerror=alert(1)&gt;', $body);
        self::assertStringNotContainsString('<img', $body);
    }

    public function testResetUrlAcceptsHttpButRejectsUnsafeSchemesAndCredentials(): void
    {
        $variable = CoreEmailTemplates::all()[0]->variables[0];
        $variables = ['reset_url' => $variable];
        [, $body] = EmailTemplateContent::render(
            'Lien', '<a href="%reset_url%">Ouvrir</a>', $variables,
            ['reset_url' => 'http://localhost:8088/reset-password/example'],
        );
        self::assertStringContainsString('href="http://localhost:8088/reset-password/example"', $body);

        foreach (['javascript:alert(1)', 'data:text/html,test', '//example.test/reset',
            'http://user:pass@example.test/reset'] as $url) {
            try {
                EmailTemplateContent::render('Lien', '<p>%reset_url%</p>', $variables, ['reset_url' => $url]);
                self::fail('Une URL dangereuse doit être refusée.');
            } catch (ApplicationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testRejectsVariableInRemovedAttribute(): void
    {
        $this->expectException(ApplicationException::class);
        EmailTemplateContent::validate('Sujet', '<p onclick="%name%">Bonjour</p>', [
            'name' => new EmailTemplateVariable('name'),
        ]);
    }

    public function testStripsScriptsAndRejectsUnknownVariables(): void
    {
        $body = EmailTemplateContent::validate('Sujet', '<script>alert(1)</script><p>Bonjour</p>', []);
        self::assertStringNotContainsString('<script', $body);

        $this->expectException(ApplicationException::class);
        EmailTemplateContent::validate('Sujet', '<p>%unknown%</p>', []);
    }

    public function testKeepsRelativeMediaForLaterSpaceValidation(): void
    {
        $body = EmailTemplateContent::validate('Sujet', '<p><img src="/medias/site/photo.png" alt="Photo"></p>', []);
        self::assertStringContainsString('src="/medias/site/photo.png"', $body);
    }

    public function testKeepsSensitiveRequiredVariableInBodyOnly(): void
    {
        $variables = ['initial_password' => new EmailTemplateVariable(
            'initial_password', allowedInSubject: false, requiredInBody: true,
        )];

        self::assertSame('<p>%initial_password%</p>', EmailTemplateContent::validate(
            'Votre compte', '<p>%initial_password%</p>', $variables,
        ));

        foreach ([
            ['Votre compte %initial_password%', '<p>%initial_password%</p>'],
            ['Votre compte', '<p>Bienvenue</p>'],
        ] as [$subject, $body]) {
            try {
                EmailTemplateContent::validate($subject, $body, $variables);
                self::fail('Un secret absent du corps ou présent dans l’objet doit être refusé.');
            } catch (ApplicationException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
