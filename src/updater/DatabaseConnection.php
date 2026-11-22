<?php

declare(strict_types=1);

namespace Tycms\Updater;

use PDO;
use RuntimeException;

/** Connexion indépendante à la même base que la configuration de la release. */
final class DatabaseConnection
{
    public static function config(string $root, string $release): object
    {
        $path = SourceConfiguration::path($root, $release);
        if (is_link($path) || !is_file($path) || class_exists('Configuration', false)) {
            throw new RuntimeException('Configuration source indisponible.');
        }
        if (!defined('APP_ROOT')) define('APP_ROOT', $root);
        require_once $path;
        if (!class_exists('Configuration', false)) throw new RuntimeException('Configuration source invalide.');
        return new \Configuration();
    }

    public static function identity(object $config): string
    {
        $type = $config->db_type ?? null;
        $host = $config->db_host ?? null;
        $name = $config->db_dbname ?? null;
        $user = $config->db_user ?? null;
        if (!is_string($type) || !in_array(strtolower($type), ['mysql', 'mysqli'], true)
            || !is_string($host) || $host === '' || preg_match('/[;\x00-\x1f\x7f]/', $host)
            || !is_string($name) || $name === '' || preg_match('/[;\x00-\x1f\x7f]/', $name)
            || !is_string($user) || $user === '' || !is_string($config->db_password ?? null)) {
            throw new RuntimeException('Paramètres MariaDB invalides.');
        }
        return hash('sha256', json_encode([$host, $name, $user], JSON_THROW_ON_ERROR));
    }

    public static function connect(object $config): PDO
    {
        self::identity($config);
        if (!extension_loaded('pdo_mysql')) throw new RuntimeException('PDO MySQL est requis.');
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10];
        if (($config->db_encryption ?? 0) > 0) {
            if (!defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
                throw new RuntimeException('Vérification TLS MariaDB non prise en charge.');
            }
            foreach (['db_ssl_key' => 'MYSQL_ATTR_SSL_KEY', 'db_ssl_cert' => 'MYSQL_ATTR_SSL_CERT',
                'db_ssl_ca' => 'MYSQL_ATTR_SSL_CA', 'db_ssl_cipher' => 'MYSQL_ATTR_SSL_CIPHER'] as $property => $constant) {
                $value = $config->$property ?? '';
                if (!is_string($value) || ($value !== '' && !defined('PDO::' . $constant))) {
                    throw new RuntimeException('Configuration TLS MariaDB invalide.');
                }
                if ($value !== '') $options[constant('PDO::' . $constant)] = $value;
            }
            $options[constant('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')] = (bool) ($config->db_ssl_verifyservercert ?? false);
        }
        return new PDO('mysql:host=' . $config->db_host . ';dbname=' . $config->db_dbname . ';charset=utf8mb4',
            $config->db_user, $config->db_password, $options);
    }
}
