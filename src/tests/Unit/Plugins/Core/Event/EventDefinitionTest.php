<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Event;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\Event\Event;
use YTassy\Tycms\CoreExts\Core\Event\EventDefinition;

final class EventDefinitionTest extends TestCase
{
    #[Test]
    public function minimalDefinitionLogsWithoutNotifying(): void
    {
        $definition = $this->definition();

        self::assertSame('core.user.role.assigned', $definition->id);
        self::assertTrue($definition->log);
        self::assertFalse($definition->notifiable);
        self::assertTrue($definition->notifyActor);
        self::assertSame([], $definition->defaultChannels);
        self::assertNull($definition->link);
    }

    #[Test]
    public function identifierRequiresTwoToFiveLowercaseSegments(): void
    {
        foreach (['core', 'core.user.role.assigned.extra.deep', 'Core.user.created', 'core..created'] as $id) {
            try {
                $this->definition(id: $id);
                self::fail(sprintf('L’identifiant "%s" aurait dû être refusé.', $id));
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    #[Test]
    public function notifiableDefinitionRequiresLogTextsAndLink(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EventDefinition(
            id: 'core.user.created',
            label: 'Compte créé',
            description: 'Un compte vient d’être créé.',
            log: false,
            notifiable: true,
            title: 'Compte créé',
            bodySelf: 'Votre compte a été créé.',
            bodyOthers: 'Le compte {user.name} a été créé.',
            link: static fn (Event $event): string => '/administrator/users',
        );
    }

    #[Test]
    public function notifiableDefinitionWithoutBodyIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EventDefinition(
            id: 'core.user.created',
            label: 'Compte créé',
            description: 'Un compte vient d’être créé.',
            notifiable: true,
            title: 'Compte créé',
            bodySelf: 'Votre compte a été créé.',
            link: static fn (Event $event): string => '/administrator/users',
        );
    }

    #[Test]
    public function notifiableDefinitionWithoutLinkIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EventDefinition(
            id: 'core.user.created',
            label: 'Compte créé',
            description: 'Un compte vient d’être créé.',
            notifiable: true,
            title: 'Compte créé',
            bodySelf: 'Votre compte a été créé.',
            bodyOthers: 'Le compte {user.name} a été créé.',
        );
    }

    #[Test]
    public function unknownCategoryIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->definition(category: 'other');
    }

    #[Test]
    public function defaultChannelsAreUniqueAndWellFormed(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->definition(defaultChannels: ['ui', 'ui']);
    }

    /** @param list<string> $defaultChannels */
    private function definition(
        string $id = 'core.user.role.assigned',
        string $category = EventDefinition::CATEGORY_USER,
        string $severity = EventDefinition::SEVERITY_INFO,
        array $defaultChannels = [],
    ): EventDefinition {
        return new EventDefinition(
            id: $id,
            label: 'Rôle attribué',
            description: 'Un rôle est attribué à un compte.',
            category: $category,
            severity: $severity,
            defaultChannels: $defaultChannels,
        );
    }
}
