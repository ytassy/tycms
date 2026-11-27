<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Job;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\BaseJobHandler;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\IJobHandler;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobContext;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobResult;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Job\JobType;

final class JobTypeTest extends TestCase
{
    public function testValidTypeExposesItsContract(): void
    {
        $type = new JobType('core.mail_welcome', 'Envoi d’e-mail', TestJobHandler::class, 7, 2);

        self::assertSame('core.mail_welcome', $type->id);
        self::assertSame('Envoi d’e-mail', $type->label);
        self::assertSame(TestJobHandler::class, $type->handlerClass);
        self::assertSame(7, $type->minTime);
        self::assertSame(2, $type->maxAttempts);

        // Sans métadonnée, la priorité reste normale : aucun handler existant
        // ne change de comportement.
        self::assertSame(JobType::PRIORITY_NORMAL, $type->priority);
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
        yield 'sans préfixe' => ['mail_welcome'];
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

    public function testHandlerMustExtendTheJobBase(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobType('core.invalid', 'Libellé', PlainJobHandler::class);
    }

    public function testLabelIsRequired(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobType('core.mail_welcome', '   ', TestJobHandler::class);
    }

    #[DataProvider('invalidMinTimes')]
    public function testMinTimeIsBounded(int $minTime): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobType('core.mail_welcome', 'Libellé', TestJobHandler::class, $minTime);
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

        new JobType('core.mail_welcome', 'Libellé', TestJobHandler::class, 5, $maxAttempts);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidMaxAttempts(): iterable
    {
        yield 'négatif' => [-1];
        yield 'au-delà du plafond' => [JobType::MAX_ATTEMPTS_MAX + 1];
    }

    #[DataProvider('invalidPriorities')]
    public function testPriorityIsBounded(int $priority): void
    {
        $this->expectException(InvalidArgumentException::class);

        new JobType('core.mail_welcome', 'Libellé', TestJobHandler::class, 5, 3, $priority);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidPriorities(): iterable
    {
        yield 'négative' => [JobType::PRIORITY_MIN - 1];
        yield 'au-delà du plafond' => [JobType::PRIORITY_MAX + 1];
    }

    public function testIntermediatePriorityIsAccepted(): void
    {
        // L'échelle reste un entier libre : 65 n'est pas un niveau nommé mais
        // reste une priorité valide.
        $type = new JobType('core.mail_welcome', 'Libellé', TestJobHandler::class, 5, 3, 65);

        self::assertSame(65, $type->priority);
    }

    public function testTypeIsBuiltFromHandlerMetadata(): void
    {
        $type = JobType::for(MetadataJobHandler::class);

        self::assertSame('plg_test.metadata', $type->id);
        self::assertSame('Test avec métadonnées', $type->label);
        self::assertSame(MetadataJobHandler::class, $type->handlerClass);
        self::assertSame(4, $type->minTime);
        self::assertSame(1, $type->maxAttempts);

        // La constante facultative JOB_PRIORITY est lue quand elle existe.
        self::assertSame(JobType::PRIORITY_HIGH, $type->priority);
    }

    public function testHandlerWithoutPriorityKeepsTheNormalLevel(): void
    {
        $type = JobType::for(DefaultPriorityJobHandler::class);

        self::assertSame(JobType::PRIORITY_NORMAL, $type->priority);
    }

    public function testPriorityMetadataMustBeAnInteger(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JOB_PRIORITY');

        JobType::for(NonIntegerPriorityJobHandler::class);
    }

    public function testHandlerWithoutMetadataIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('JOB_TYPE');

        JobType::for(BareJobHandler::class);
    }

    public function testUnknownHandlerClassIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('introuvable');

        JobType::for('YTassy\Tycms\Absent\Handler');
    }
}

/**
 * Handler minimal : le type vérifie seulement que la classe respecte le
 * contrat, il n'exécute rien.
 */
final class TestJobHandler extends BaseJobHandler
{
    public function handle(JobContext $context, array $payload): JobResult
    {
        return JobResult::success();
    }
}

/**
 * Contrat incomplet : implémenter l'interface ne suffit pas, le handler doit
 * étendre `BaseJobHandler` pour recevoir l'Application isolée du principal.
 */
final class PlainJobHandler implements IJobHandler
{
    public function handle(JobContext $context, array $payload): JobResult
    {
        return JobResult::success();
    }
}

/** Handler complet : métadonnées déclarées à côté du code. */
final class MetadataJobHandler extends BaseJobHandler
{
    public const JOB_TYPE = 'plg_test.metadata';
    public const JOB_LABEL = 'Test avec métadonnées';
    public const JOB_MIN_TIME = 4;
    public const JOB_MAX_ATTEMPTS = 1;
    public const JOB_PRIORITY = JobType::PRIORITY_HIGH;

    public function handle(JobContext $context, array $payload): JobResult
    {
        return JobResult::success();
    }
}

/** Handler complet sans priorité déclarée : le type doit rester en niveau normal. */
final class DefaultPriorityJobHandler extends BaseJobHandler
{
    public const JOB_TYPE = 'plg_test.default';
    public const JOB_LABEL = 'Test sans priorité';
    public const JOB_MIN_TIME = 1;
    public const JOB_MAX_ATTEMPTS = 1;

    public function handle(JobContext $context, array $payload): JobResult
    {
        return JobResult::success();
    }
}

/** Priorité mal typée : la métadonnée facultative est contrôlée à l'enregistrement. */
final class NonIntegerPriorityJobHandler extends BaseJobHandler
{
    public const JOB_TYPE = 'plg_test.bad_priority';
    public const JOB_LABEL = 'Test priorité invalide';
    public const JOB_MIN_TIME = 1;
    public const JOB_MAX_ATTEMPTS = 1;
    public const JOB_PRIORITY = 'haute';

    public function handle(JobContext $context, array $payload): JobResult
    {
        return JobResult::success();
    }
}

/** Handler sans métadonnées : `JobType::for()` doit le refuser explicitement. */
final class BareJobHandler extends BaseJobHandler
{
    public function handle(JobContext $context, array $payload): JobResult
    {
        return JobResult::success();
    }
}
