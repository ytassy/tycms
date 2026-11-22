<?php

declare(strict_types=1);

ini_set('display_errors', '0');

use YTassy\Tycms\Application;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Tests\Support\Integration\TestDatabase;
use YTassy\Tycms\Tests\Support\Integration\TestEnvironment;
use YTassy\Tycms\Tests\Support\Integration\TestFixtures;

require dirname(__DIR__) . '/tests/bootstrap.php';

if (PHP_SAPI !== 'cli' || $argc !== 2 || $argv[1] !== '--reset') {
    fwrite(STDERR, "Usage : php tools/test_db_prepare.php --reset\n");
    exit(2);
}

try {
    $environment = TestEnvironment::load();
    $database = new TestDatabase($environment);
    $database->resetSchema();
    $environment->resetRuntime();

    $application = new Application($environment->configuration());
    $application->initialiseOrThrow(new Request(host: TestFixtures::SITE_HOST));
    TestFixtures::install($application);
    TestFixtures::assertPrepared($database);

    fwrite(STDOUT, "Base de test prête : {$environment->database}.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Préparation impossible : ' . $exception->getMessage() . "\n");
    exit(1);
}
