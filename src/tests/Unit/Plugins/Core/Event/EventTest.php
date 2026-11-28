<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Event;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\Event\Event;
use YTassy\Tycms\CoreExts\Core\Event\EventActorType;
use YTassy\Tycms\CoreExts\Core\Event\EventDefinition;

final class EventTest extends TestCase
{
    #[Test]
    public function recipientsAreDeduplicatedAndDatesAreStoredInUtc(): void
    {
        $event = new Event(
            $this->definition(),
            4,
            ['user' => ['id' => 12]],
            [5, 5, 8],
            42,
            EventActorType::USER,
            'user',
            12,
            null,
            new DateTimeImmutable('2026-01-01 12:00:00', new DateTimeZone('Europe/Paris')),
        );

        self::assertSame([5, 8], $event->recipientUserIds);
        self::assertSame('UTC', $event->occurredAt->getTimezone()->getName());
        self::assertSame('2026-01-01 11:00:00', $event->occurredAt->format('Y-m-d H:i:s'));
        self::assertSame('core.user.role.assigned', $event->type);
    }

    #[Test]
    public function dataMustBeAMap(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Event($this->definition(), 1, ['premier', 'second'], []);
    }

    #[Test]
    public function sensitiveKeysAreRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Event($this->definition(), 1, ['user' => ['access_token' => 'valeur']], []);
    }

    #[Test]
    public function tooDeepDataIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Event($this->definition(), 1, ['a' => ['b' => ['c' => ['d' => 'x']]]], []);
    }

    #[Test]
    public function boundedNestedDataIsAccepted(): void
    {
        $event = new Event($this->definition(), 1, ['a' => ['b' => ['c' => 'x']]], []);

        self::assertSame('x', $event->data['a']['b']['c']);
    }

    #[Test]
    public function recipientsMustBePositiveIntegers(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Event($this->definition(), 1, [], [0]);
    }

    #[Test]
    public function actorIdCannotBeCombinedWithANonUserType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Event($this->definition(), 1, [], [], 42, EventActorType::SYSTEM);
    }

    #[Test]
    public function subjectIdRequiresASubjectType(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Event($this->definition(), 1, [], [], null, EventActorType::USER, null, 12);
    }

    #[Test]
    public function absoluteOrAmbiguousUrlPathsAreRefused(): void
    {
        $invalidPaths = [
            'https://example.test/x',
            '//example.test/x',
            '/a//b',
            '/a\\b',
            '/a/../b',
            "/a\nb",
            '',
        ];

        foreach ($invalidPaths as $path) {
            try {
                new Event($this->definition(), 1, [], [], urlPath: $path);
                self::fail(sprintf('Le chemin "%s" aurait dû être refusé.', $path));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function relativeUrlPathIsKept(): void
    {
        $event = new Event($this->definition(), 1, [], [], urlPath: '/administrator/users/12');

        self::assertSame('/administrator/users/12', $event->urlPath);
    }

    private function definition(): EventDefinition
    {
        return new EventDefinition(
            id: 'core.user.role.assigned',
            label: 'Rôle attribué',
            description: 'Un rôle est attribué à un compte.',
        );
    }
}
