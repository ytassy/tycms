<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core\Event;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use YTassy\Tycms\CoreExts\Core\Event\EventAudience;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclUserResolver;

/**
 * Contrat de la spécification d'audience.
 *
 * Le workflow décrit la source ; le resolver reçoit la limite déjà calculée par
 * l'émetteur. Une liste directe ne consulte jamais la base.
 */
final class EventAudienceTest extends TestCase
{
    #[Test]
    public function directUsersAreReturnedWithoutResolverCall(): void
    {
        $resolver = $this->createMock(AclUserResolver::class);
        $resolver->expects(self::never())->method(self::anything());

        self::assertSame([5, 8], EventAudience::users([5, 8])->resolve($resolver, 10));
    }

    #[Test]
    public function everyResolvedSourceDelegatesWithTheGivenLimit(): void
    {
        $resolver = $this->createMock(AclUserResolver::class);

        $resolver->expects(self::once())
            ->method('usersWithGlobalPermission')
            ->with('plg_core__USER_ROLES_READ', 4, 7)
            ->willReturn([3]);

        $resolver->expects(self::once())
            ->method('usersWithPermissionOnObject')
            ->with('plg_core__PAGE_READ', 'plg_core', 'page', 12, 4, 7)
            ->willReturn([4]);

        $resolver->expects(self::once())
            ->method('usersWithGlobalRole')
            ->with(5, 4, 7)
            ->willReturn([5]);

        $resolver->expects(self::once())
            ->method('usersWithRoleOnObject')
            ->with(5, 'plg_core', 'page', 12, 4, 7)
            ->willReturn([6]);

        self::assertSame(
            [3],
            EventAudience::globalPermission('plg_core__USER_ROLES_READ', 4)->resolve($resolver, 7),
        );
        self::assertSame(
            [4],
            EventAudience::permissionOnObject('plg_core__PAGE_READ', 'plg_core', 'page', 12, 4)->resolve($resolver, 7),
        );
        self::assertSame([5], EventAudience::globalRole(5, 4)->resolve($resolver, 7));
        self::assertSame(
            [6],
            EventAudience::roleOnObject(5, 'plg_core', 'page', 12, 4)->resolve($resolver, 7),
        );
    }
}
