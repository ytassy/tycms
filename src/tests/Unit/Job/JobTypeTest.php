<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Job;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\IJobHandler;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobResult;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobType;

final class JobTypeTest extends TestCase
{
    public function testValidTypeExposesItsContract(): void
    {
        $type = new JobType('core.mail_send', 'Envoi d’e-mail', TestJobHandler::class, 7, 2);

        self::assertSame('core.mail_send', $type->id);
        self::assertSame('Envoi d’e-mail', $type->label);
        self::assertSame(TestJobHandler::class, $type->handlerClass);
        self::assertSame(7, $type->minTime);
        self::assertSame(2, $type->maxAttempts);
        self::assertSame('core', $type->producer());
    }

    #[DataProvider('invalidIdentifiers')]
    public function testInvalidIdentifierIsRefused(string $id): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobType($id, 'Libellé', TestJobHandler::class);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIdentifiers(): iterable
    {
        yield 'sans préfixe' => ['mail_send'];
        yield 'majuscules' => ['Core.MailSend'];
        yield 'tiret' => ['core.mail-send'];
        yield 'deux points' => ['core.mail.send'];
        yield 'vide' => [''];
    }

    public function testHandlerMustImplementTheContract(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobType('core.invalid', 'Libellé', \stdClass::class);
    }

    public function testHandlerClassMustExist(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobType('core.invalid', 'Libellé', 'YTassy\\Tycms\\Absent\\Handler');
    }

    public function testLabelIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobType('core.mail_send', '   ', TestJobHandler::class);
    }

    #[DataProvider('invalidMinTimes')]
    public function testMinTimeIsBounded(int $minTime): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobType('core.mail_send', 'Libellé', TestJobHandler::class, $minTime);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidMinTimes(): iterable
    {
        yield 'zéro' => [0];
        yield 'négatif' => [-1];
        yield 'au-delà du budget' => [JobType::MIN_TIME_MAX + 1];
    }

    #[DataProvider('invalidMaxAttempts')]
    public function testMaxAttemptsIsBounded(int $maxAttempts): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobType('core.mail_send', 'Libellé', TestJobHandler::class, 5, $maxAttempts);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidMaxAttempts(): iterable
    {
        yield 'négatif' => [-1];
        yield 'au-delà du plafond' => [JobType::MAX_ATTEMPTS_MAX + 1];
    }
}

/**
 * Handler minimal : le type vérifie seulement que la classe respecte le
 * contrat, il n'exécute rien.
 */
final class TestJobHandler implements IJobHandler
{
    public function handle(JobContext $context, array $payload): JobResult
    {
        return JobResult::success();
    }
}
