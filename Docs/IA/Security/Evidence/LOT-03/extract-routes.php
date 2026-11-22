<?php

declare(strict_types=1);

// Métadonnées seules : ne démarrer ni Application, ni configuration locale,
// ni contrôleur. Les arguments d'attributs lus sont ceux du code audité.
$root = dirname(__DIR__, 5);
require $root . '/src/releases/vdev/vendor/autoload.php';

use YTassy\Tycms\Routing\Annotation\Doc\Controller;
use YTassy\Tycms\Routing\Annotation\Doc\ControllerMethod;

$rows = [];
$classes = [];
foreach (['src/releases/vdev/core_extensions', 'src/extensions'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
        $root . '/' . $directory, FilesystemIterator::SKIP_DOTS,
    ));
    foreach ($files as $file) {
        if (!$file->isFile() || $file->getExtension() !== 'php') continue;
        $source = file_get_contents($file->getPathname());
        if (!preg_match('/^#\[Controller\(/m', $source)
            || !preg_match('/^namespace\s+([^;]+);/m', $source, $namespace)
            || !preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $class)) continue;

        $reflection = new ReflectionClass(trim($namespace[1]) . '\\' . $class[1]);
        $attribute = $reflection->getAttributes(Controller::class);
        if (count($attribute) !== 1) throw new RuntimeException('Déclaration ambiguë.');
        $definition = $attribute[0]->getArguments();
        $classAttributes = array_map(static fn ($a) => $a->getName(), $reflection->getAttributes());
        $relative = substr($file->getPathname(), strlen($root) + 1);
        $classes[] = ['classe' => $reflection->getName(), 'source' => $relative];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = array_map(static fn ($a) => $a->getName(), $method->getAttributes());
            $params = [];
            foreach ($method->getAttributes() as $param) {
                if (str_contains($param->getName(), '\\ControllerParam')) {
                    $args = $param->getArguments();
                    foreach ($args as &$value) {
                        if ($value instanceof BackedEnum) $value = $value->value;
                    }
                    unset($value);
                    $params[] = ['attribut' => (new ReflectionClass($param->getName()))->getShortName(), 'arguments' => $args];
                }
            }
            foreach ($method->getAttributes(ControllerMethod::class) as $action) {
                $args = $action->getArguments();
                $path = '/' . implode('/', array_filter([
                    trim($definition['path'], '/'), trim($args['path'], '/'),
                ], static fn ($s) => $s !== ''));
                foreach ($args['methods'] ?? ['GET'] as $verb) {
                    $rows[] = [
                        'classe' => $reflection->getName(),
                        'methode' => $reflection->getShortName() . '::' . $method->getName(),
                        'nom' => $definition['name'] . $args['name'],
                        'verbe' => $verb instanceof BackedEnum ? $verb->value : $verb,
                        'chemin' => $path,
                        'api' => $definition['isApi'] ?? false,
                        'without_csrf' => $definition['withoutCsrf'] ?? false,
                        'attributs_classe' => $classAttributes,
                        'attributs_methode' => $attributes,
                        'parametres' => $params,
                        'source' => $relative,
                        'ligne' => $method->getStartLine(),
                    ];
                }
            }
        }
    }
}
usort($rows, static fn ($a, $b) => [$a['source'], $a['ligne'], $a['verbe']] <=> [$b['source'], $b['ligne'], $b['verbe']]);
echo json_encode(['classes' => $classes, 'routes' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
