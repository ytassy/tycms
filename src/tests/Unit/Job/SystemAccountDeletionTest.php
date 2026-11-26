<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Job;

use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\UserAdminHelper;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class SystemAccountDeletionTest extends FrameworkTestCase
{
    #[DataProvider('reservedIds')]
    public function testReservedAccountCannotBeDeleted(int $userId): void
    {
        $app = $this->createApplication();
        $db = $this->createMock(Database::class);

        // La garde précède toute requête : aucune suppression ne doit être
        // tentée, même si un appelant oubliait le contrôle du workflow.
        $db->expects(self::never())->method('execute');
        new ReflectionProperty(Application::class, 'database')->setValue($app, $db);

        $helper = new UserAdminHelper($app);

        $this->expectException(TechException::class);
        $this->expectExceptionMessage('Les comptes d’identifiant réservé ne peuvent pas être supprimés.');

        $helper->deleteUser($userId, null);
    }

    /** @return iterable<string, array{int}> */
    public static function reservedIds(): iterable
    {
        yield 'administrateur initial' => [1];
        yield 'compte système de la file' => [2];
        yield 'dernier identifiant réservé' => [UserAdminHelper::RESERVED_USER_ID_MAX];
    }

    public function testAccountOutsideTheReservedRangeCanBeDeleted(): void
    {
        $app = $this->createApplication();
        $db = $this->createMock(Database::class);

        $db->expects(self::once())
            ->method('execute')
            ->with(self::stringContains('DELETE FROM users'), [UserAdminHelper::RESERVED_USER_ID_MAX + 1])
            ->willReturn(1);
        new ReflectionProperty(Application::class, 'database')->setValue($app, $db);

        // Aucun groupe personnel : la suppression de la ligne suffit.
        (new UserAdminHelper($app))->deleteUser(UserAdminHelper::RESERVED_USER_ID_MAX + 1, null);

        self::assertTrue(true);
    }
}
