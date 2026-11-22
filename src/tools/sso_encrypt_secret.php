<?php

// Usage: run this script and enter the client secret when prompted.
require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/configuration.php';

use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Crypt\CryptHelper;
fwrite(STDERR, "Secret client Google : ");
$input = fgets(STDIN);
$clientSecret = is_string($input) ? rtrim($input, "\r\n") : '';
if ($clientSecret === '') {
    fwrite(STDERR, "Le secret client Google est absent.\n");
    exit(1);
}

try {
    $crypto = new CryptHelper(new Application(new Configuration()));
    echo bin2hex($crypto->encrypt($clientSecret, 'provider:v1')), PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
