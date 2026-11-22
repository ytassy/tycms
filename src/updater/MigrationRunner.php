<?php

declare(strict_types=1);

namespace Tycms\Updater;

use RuntimeException;

/** Charge seulement le code de la release cible dans la requête de migration. */
final class MigrationRunner
{
    public static function run(string $root, string $target, string $version, ?string $databaseIdentity): void
    {
        $config = self::load($root, $target, $version, $databaseIdentity);
        (new \YTassy\Tycms\Application($config))->runMigrationsOrThrow();
    }

    /** Vérifie le démarrage sans session et sans permettre une installation SQL automatique. */
    public static function check(string $root, string $target, string $version, ?string $databaseIdentity): void
    {
        $config = self::load($root, $target, $version, $databaseIdentity);
        $pdo = DatabaseConnection::connect($config);
        $query = $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'migrations' AND TABLE_TYPE = 'BASE TABLE'");
        if ((int) $query->fetchColumn() !== 1) throw new RuntimeException('Journal des migrations absent.');
        (new \YTassy\Tycms\Application($config))->initialiseOrThrow(new \YTassy\Tycms\Request\Request(), false);
    }

    private static function load(string $root, string $target, string $version, ?string $databaseIdentity): object
    {
        foreach (['version.php', 'vendor/autoload.php', 'configuration.php'] as $file) {
            $path = $target . '/' . $file;
            if (is_link($path) || !is_file($path) || realpath($path) !== $path) {
                throw new RuntimeException('Release cible incomplète.');
            }
        }
        if (class_exists('Configuration', false)) throw new RuntimeException('La requête de migration doit être isolée.');
        if (!defined('APP_ROOT')) define('APP_ROOT', $root);
        require_once $target . '/version.php';
        if (!defined('APP_VERSION') || APP_VERSION !== $version
            || !defined('APP_VERSION_STRING') || APP_VERSION_STRING !== str_replace('.', '_', $version)) {
            throw new RuntimeException('Version cible incohérente.');
        }
        if (!defined('ASSETS_CORE_PATH')) define('ASSETS_CORE_PATH', '/assets_core_v' . APP_VERSION_STRING);
        require_once $target . '/vendor/autoload.php';
        require_once $target . '/configuration.php';
        if (!class_exists('Configuration', false)) throw new RuntimeException('Configuration cible invalide.');
        $config = new \Configuration();
        if (($config->RESET_DATABASE ?? false) !== false || ($config->offline ?? null) !== true) {
            throw new RuntimeException('Configuration de migration non sûre.');
        }
        if ($databaseIdentity !== null && !hash_equals($databaseIdentity, DatabaseConnection::identity($config))) {
            throw new RuntimeException('La base cible a changé depuis la sauvegarde.');
        }
        return $config;
    }
}
