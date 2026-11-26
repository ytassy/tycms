<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use PHPUnit\Framework\Attributes\DataProvider;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobContext;
use YTassy\Tycms\CoreExts\Core\Mail\SendMailHtmlJobHandler;
use YTassy\Tycms\CoreExts\Core\Mail\SendMailTemplateJobHandler;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

/**
 * Refus des handlers d'e-mail sur un payload invalide.
 *
 * Ces refus interviennent avant tout appel SMTP : le test vérifie le contrat,
 * pas le transport.
 */
final class MailJobHandlersTest extends FrameworkTestCase
{
    #[DataProvider('invalidHtmlPayloads')]
    public function testHtmlHandlerRefusesInvalidPayload(array $payload, ?int $spaceId = 1): void
    {
        $handler = new SendMailHtmlJobHandler($this->createApplication());

        $result = $handler->handle($this->context($spaceId, SendMailHtmlJobHandler::JOB_TYPE), $payload);

        self::assertFalse($result->success);
    }

    /** @return iterable<string, array{array<string, mixed>, 2?: int|null}> */
    public static function invalidHtmlPayloads(): iterable
    {
        $valid = ['to' => 'alice@example.test', 'subject' => 'Sujet', 'html' => '<p>Bonjour</p>'];

        yield 'destinataire absent' => [array_diff_key($valid, ['to' => null])];
        yield 'destinataire invalide' => [['to' => 'pas-une-adresse'] + $valid];
        yield 'objet vide' => [['subject' => ''] + $valid];
        yield 'objet multiligne' => [['subject' => "Sujet\r\nBcc: intrus@example.test"] + $valid];
        yield 'corps vide' => [['html' => ''] + $valid];
        yield 'corps sans texte ni image' => [['html' => '<script>alert(1)</script>'] + $valid];
        yield 'espace absent' => [$valid, null];
    }

    #[DataProvider('invalidTemplatePayloads')]
    public function testTemplateHandlerRefusesInvalidPayload(array $payload, ?int $spaceId = 1): void
    {
        $handler = new SendMailTemplateJobHandler($this->createApplication());

        $result = $handler->handle($this->context($spaceId, SendMailTemplateJobHandler::JOB_TYPE), $payload);

        self::assertFalse($result->success);
    }

    /** @return iterable<string, array{array<string, mixed>, 2?: int|null}> */
    public static function invalidTemplatePayloads(): iterable
    {
        $valid = ['template' => 'plg_core__registration_welcome', 'to' => 'alice@example.test', 'vars' => []];

        yield 'modèle absent' => [array_diff_key($valid, ['template' => null])];
        yield 'modèle vide' => [['template' => '  '] + $valid];
        yield 'destinataire invalide' => [['to' => 'pas-une-adresse'] + $valid];
        yield 'espace absent' => [$valid, null];
    }

    private function context(?int $spaceId, string $type): JobContext
    {
        return new JobContext(
            jobId: 1,
            type: $type,
            spaceId: $spaceId,
            principal: 'none',
            execUserId: null,
            producer: 'plg_core',
            attempts: 1,
            maxAttempts: 2,
        );
    }
}
