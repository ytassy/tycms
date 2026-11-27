<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\MailLogHelper;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

/**
 * Contrat de masquage du journal des e-mails, sans base ni SMTP.
 */
final class MailLogHelperTest extends FrameworkTestCase
{
    /** @param list<string> $secrets */
    #[DataProvider('secretCases')]
    public function testSecretsAreMaskedInOnePass(?string $value, array $secrets, ?string $expected): void
    {
        self::assertSame($expected, MailLogHelper::maskSecrets($value, $secrets));
    }

    /** @return iterable<string, array{?string, array<int, mixed>, ?string}> */
    public static function secretCases(): iterable
    {
        yield 'aucun secret' => ['Bonjour', [], 'Bonjour'];
        yield 'valeur nulle' => [null, ['secret'], null];
        yield 'remplacement simple' => ['Mot de passe : abc12345', ['abc12345'], 'Mot de passe : [SECRET]'];
        yield 'plusieurs occurrences' => ['abc12345 et abc12345', ['abc12345'], '[SECRET] et [SECRET]'];
        yield 'secret vide ignoré' => ['Bonjour', [''], 'Bonjour'];
        yield 'secret court ignoré' => ['Bonjour', ['abc'], 'Bonjour'];
        yield 'chevauchement, plus long d’abord' => ['abcdef1234', ['abcdef', 'abcdef1234'], '[SECRET]'];
        yield 'marqueur non remasqué' => ['SECRET', ['SECRET'], '[SECRET]'];
        yield 'valeur non texte ignorée' => ['Bonjour', [123], 'Bonjour'];
    }

    public function testNegativePurgeThresholdIsRejected(): void
    {
        $helper = new MailLogHelper($this->createApplication());

        $this->expectException(\InvalidArgumentException::class);
        $helper->purge(-1);
    }

    public function testRawAndHtmlEscapedVariantsAreBothMasked(): void
    {
        $secret = 'p<a&s"';
        $value = 'brut: ' . $secret
            . ' html: ' . htmlspecialchars($secret, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        self::assertSame(
            'brut: [SECRET] html: [SECRET]',
            MailLogHelper::maskSecrets($value, [$secret]),
        );
    }
}
