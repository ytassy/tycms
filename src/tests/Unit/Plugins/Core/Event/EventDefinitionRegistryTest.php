<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Event;

use PHPUnit\Framework\Attributes\Test;
use YTassy\Tycms\CoreExts\Core\Event\EventDefinition;
use YTassy\Tycms\CoreExts\Core\Event\EventDefinitionRegistry;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class EventDefinitionRegistryTest extends FrameworkTestCase
{
    #[Test]
    public function definitionIsIndexedWithItsOwner(): void
    {
        $registry = new EventDefinitionRegistry($this->createApplication());
        $registry->withOwner('plg_core', function () use ($registry): void {
            $registry->register($this->definition());
        });

        self::assertTrue($registry->has('core.user.role.assigned'));
        self::assertSame('plg_core', $registry->ownerOf('core.user.role.assigned'));
        self::assertSame('Rôle attribué', $registry->get('core.user.role.assigned')?->label);
    }

    #[Test]
    public function duplicateIdentifierIsRefusedAndNamesBothOwners(): void
    {
        $registry = new EventDefinitionRegistry($this->createApplication());
        $registry->withOwner('plg_core', fn () => $registry->register($this->definition()));

        $this->expectException(TechException::class);
        $this->expectExceptionMessageMatches('/plg_core.*plg_webhook/');

        $registry->withOwner('plg_webhook', fn () => $registry->register($this->definition()));
    }

    #[Test]
    public function ownerIsRestoredAfterADeclarationError(): void
    {
        $registry = new EventDefinitionRegistry($this->createApplication());
        $registry->withOwner('plg_core', fn () => $registry->register($this->definition()));

        try {
            $registry->withOwner('plg_webhook', fn () => $registry->register($this->definition()));
        } catch (TechException) {
            // La déclaration suivante doit être attribuée à son propre auteur.
        }

        $registry->register(new EventDefinition(
            id: 'core.page.published',
            label: 'Page publiée',
            description: 'Une page est publiée.',
        ));

        self::assertNull($registry->ownerOf('core.page.published'));
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
