<?php

declare(strict_types=1);

// Reproduire la racine du bootstrap HTTP sans démarrer le site.
if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

// Charger les constantes de la release sans exécuter son point d’entrée HTTP.
require_once APP_ROOT . '/releases/vdev/version.php';
define('ASSETS_CORE_PATH', '/assets_core_v' . APP_VERSION_STRING);

require APP_ROOT . '/releases/vdev/vendor/autoload.php';

// Charger uniquement la configuration de test héritant des valeurs de la release,
// jamais la configuration locale ni les secrets du site.
require_once __DIR__ . '/Support/Configuration.php';

date_default_timezone_set('Europe/Paris');
