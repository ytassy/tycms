<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Support\Integration;

use RuntimeException;
use YTassy\Tycms\Application;

final class TestFixtures
{
    public const ROOT_SPACE_ID = 1;
    public const SITE_SPACE_ID = 2;
    public const ROOT_HOME_ITEM_ID = 1;
    public const SITE_HOME_ITEM_ID = 10;
    public const SITE_HOST = 'site.integration.test';
    public const DEFAULT_LOCALE = 'fr';
    public const SITE_SECOND_LOCALE = 'en';

    public static function install(Application $application): void
    {
        $db = $application->getDatabase();
        $seed = $db->selectOneOrNull('SELECT id FROM spaces WHERE id = %', [self::SITE_SPACE_ID]);
        if ($seed === null) {
            throw new RuntimeException('Les migrations n’ont pas créé le Space de démonstration.');
        }

        $db->execute('UPDATE spaces SET hosts = %, medias_dir = % WHERE id = %', [
            [self::SITE_HOST], 'site-fixture', self::SITE_SPACE_ID,
        ]);
        $db->execute('UPDATE spaces SET hosts = %, medias_dir = % WHERE id = %', [
            [], 'root-fixture', self::ROOT_SPACE_ID,
        ]);
        $db->execute(
            'INSERT INTO space_languages (space_id, locale, is_default, enabled, sort_order) VALUES (%, %, %, %, %)',
            [self::SITE_SPACE_ID, self::SITE_SECOND_LOCALE, false, true, 10],
        );
    }

    public static function assertPrepared(TestDatabase $database): void
    {
        $result = $database->connection()->query(
            'SELECT (SELECT COUNT(*) FROM spaces WHERE id IN (1, 2)) AS spaces_count, '
            . '(SELECT COUNT(*) FROM menu_items WHERE id IN (1, 10)) AS home_count, '
            . '(SELECT COUNT(*) FROM pages) AS pages_count, '
            . '(SELECT hosts FROM spaces WHERE id = 2) AS site_hosts, '
            . '(SELECT COUNT(*) FROM space_languages WHERE space_id = 1 AND locale = \'fr\' AND is_default = 1 AND enabled = 1) AS root_locale, '
            . '(SELECT COUNT(*) FROM space_languages WHERE space_id = 2 AND locale = \'fr\' AND is_default = 1 AND enabled = 1) AS site_default_locale, '
            . '(SELECT COUNT(*) FROM space_languages WHERE space_id = 2 AND locale = \'en\' AND is_default = 0 AND enabled = 1) AS site_second_locale'
        )->fetch_assoc();
        if ((int) $result['spaces_count'] !== 2
            || (int) $result['home_count'] !== 2
            || (int) $result['pages_count'] < 2
            || json_decode($result['site_hosts'], true) !== [self::SITE_HOST]
            || (int) $result['root_locale'] !== 1
            || (int) $result['site_default_locale'] !== 1
            || (int) $result['site_second_locale'] !== 1) {
            throw new RuntimeException('Fixtures incomplètes : lancez php tools/test_db_prepare.php --reset.');
        }
    }
}
