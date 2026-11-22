<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

try {
    $tools = __DIR__ . '/functions.php';
    if (!is_file($tools) || !is_readable($tools)) throw new RuntimeException('Outil de build requis absent ou illisible : ' . $tools);
    require_once $tools;

    if (($argv[1] ?? '') === '--select' && $argc <= 3) {
        $selected = extensionBuildSelection(dirname(__DIR__), $argv[2] ?? null);
        if ($selected !== null) echo $selected . PHP_EOL;
    } elseif (($argv[1] ?? '') === '--prepare' && $argc === 3) {
        echo extensionBuildPrepare(dirname(__DIR__), $argv[2]) . PHP_EOL;
    } elseif (($argv[1] ?? '') === '--compile' && $argc === 3) {
        extensionBuildCompile($argv[2]);
    } else {
        throw new RuntimeException('Usage : build.php --select [plugins/Lms] | --prepare plugins/Lms | --compile /work');
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Build d’extension interrompu : ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
