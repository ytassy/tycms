<?php

declare(strict_types=1);

// Les fonctions sont également accessibles aux contrôles ciblés, sans lancer le build.
require_once __DIR__ . '/functions.php';

try {
    if ($argc === 3 && $argv[1] === '--compile') {
        compileRelease($argv[2], '/dist');
        exit(0);
    }

    if ($argc > 2 || ($argc === 2 && $argv[1] !== '--prepare-only')) {
        throw new RuntimeException('Usage : php build/build.php [--prepare-only]');
    }

    buildRelease(dirname(__DIR__), $argc === 2);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Build interrompu : ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
