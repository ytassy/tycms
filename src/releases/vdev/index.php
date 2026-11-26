<?php

define('APP_ROOT', dirname(__DIR__,2));


$autoLoadFile = __DIR__ .'/vendor/autoload.php';
$confFile = __DIR__ . '/configuration.php';
$versionFile = __DIR__ . '/version.php';

require_once $versionFile;

if (is_file($confFile) == false) {
    echo "Configuration File not found:  " . $confFile;
    echo "\n<br/> Copiez configuration.sample.php vers configuration.php et renseignez les réglages importants indiqués en commentaire.";
    exit(1);
}

require_once $autoLoadFile;
require_once $confFile;

define('ASSETS_CORE_PATH', '/assets_core_v' . APP_VERSION_STRING);

use YTassy\Tycms\Application;
use YTassy\Tycms\ErrorPages\ErrorPages;

$conf = new Configuration();

// Appliquer les réglages PHP d'erreurs avant la moindre réponse ou page d'erreur.
// Les erreurs survenues avant le chargement de configuration.php restent régies par php.ini.
$conf->applyPhpErrorSettings();

if ($conf->offline){
    ErrorPages::printErrorPageAndExit($conf->site_name, $conf->offline_message, 503, $conf, null,
        false, false, false, false);
    exit();
}

$app = new Application($conf);
$app->initialise();
$response = $app->executeAndGetResponse();
$app->sendResponse($response);

