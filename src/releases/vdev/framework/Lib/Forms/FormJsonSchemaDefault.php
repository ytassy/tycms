<?php

declare(strict_types=1);

namespace YTassy\Tycms\Lib\Forms;

/** Builds a predictable JSON value from the portable schema subset used by core.json. */
final class FormJsonSchemaDefault
{
    /** @param array<string,mixed> $field */
    public static function fromField(array $field): mixed
    {
        $schemaDocument = $field['schema'] ?? null;
        if (!is_array($schemaDocument)) {
            return null;
        }

        $schema = $schemaDocument['schema'] ?? $schemaDocument;
        if (!is_array($schema)) {
            return null;
        }

        $references = $schemaDocument['schemas_ref'] ?? $field['schemas'] ?? [];
        if (!is_array($references)) {
            $references = [];
        }

        return self::fromSchema($schema, $references, $schema, [], 0);
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $references
     * @param array<string,mixed> $rootSchema
     * @param array<string,bool> $resolvedReferences
     */
    private static function fromSchema(
        array $schema,
        array $references,
        array $rootSchema,
        array $resolvedReferences,
        int $depth,
    ): mixed {
        if ($depth >= 32) {
            return null;
        }

        if (array_key_exists('default', $schema)) {
            return $schema['default'];
        }

        if (array_key_exists('const', $schema)) {
            return $schema['const'];
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            return $schema['enum'][0];
        }

        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            $referenceName = $schema['$ref'];
            if (isset($resolvedReferences[$referenceName])) {
                return null;
            }

            $resolvedSchema = self::resolveReference($referenceName, $references, $rootSchema);
            if ($resolvedSchema !== null) {
                $resolvedReferences[$referenceName] = true;
                return self::fromSchema($resolvedSchema, $references, $rootSchema, $resolvedReferences, $depth + 1);
            }
        }

        foreach (['allOf', 'anyOf', 'oneOf'] as $composition) {
            if (!isset($schema[$composition]) || !is_array($schema[$composition]) || $schema[$composition] === []) {
                continue;
            }

            $firstSchema = $schema[$composition][0] ?? null;
            if (is_array($firstSchema)) {
                return self::fromSchema($firstSchema, $references, $rootSchema, $resolvedReferences, $depth + 1);
            }
        }

        $type = $schema['type'] ?? (isset($schema['properties']) ? 'object' : (isset($schema['items']) ? 'array' : null));
        if (is_array($type)) {
            $type = current(array_filter($type, static fn (mixed $item): bool => $item !== 'null')) ?: ($type[0] ?? null);
        }

        return match ($type) {
            'object' => self::getObjectDefault($schema, $references, $rootSchema, $resolvedReferences, $depth),
            'array' => self::getArrayDefault($schema, $references, $rootSchema, $resolvedReferences, $depth),
            'string' => '',
            'integer', 'number' => isset($schema['minimum']) && (is_int($schema['minimum']) || is_float($schema['minimum']))
                ? $schema['minimum']
                : 0,
            'boolean' => false,
            default => null,
        };
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $references
     * @param array<string,mixed> $rootSchema
     * @param array<string,bool> $resolvedReferences
     * @return array<string,mixed>
     */
    private static function getObjectDefault(
        array $schema,
        array $references,
        array $rootSchema,
        array $resolvedReferences,
        int $depth,
    ): array {
        $properties = $schema['properties'] ?? [];
        $required = $schema['required'] ?? [];
        if (!is_array($properties) || !is_array($required)) {
            return [];
        }

        $value = [];
        foreach ($required as $name) {
            if (!is_string($name) || !isset($properties[$name]) || !is_array($properties[$name])) {
                continue;
            }

            $value[$name] = self::fromSchema(
                $properties[$name],
                $references,
                $rootSchema,
                $resolvedReferences,
                $depth + 1,
            );
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,mixed> $references
     * @param array<string,mixed> $rootSchema
     * @param array<string,bool> $resolvedReferences
     * @return list<mixed>
     */
    private static function getArrayDefault(
        array $schema,
        array $references,
        array $rootSchema,
        array $resolvedReferences,
        int $depth,
    ): array {
        $minimumItems = $schema['minItems'] ?? 0;
        $itemsSchema = $schema['items'] ?? null;
        if (!is_int($minimumItems) || $minimumItems < 1 || !is_array($itemsSchema)) {
            return [];
        }

        $value = [];
        for ($index = 0; $index < $minimumItems; ++$index) {
            $value[] = self::fromSchema($itemsSchema, $references, $rootSchema, $resolvedReferences, $depth + 1);
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $references
     * @param array<string,mixed> $rootSchema
     * @return array<string,mixed>|null
     */
    private static function resolveReference(string $reference, array $references, array $rootSchema): ?array
    {
        if (isset($references[$reference]) && is_array($references[$reference])) {
            return $references[$reference];
        }

        if ($reference === '#') {
            return $rootSchema;
        }

        if (!str_starts_with($reference, '#/')) {
            return null;
        }

        $value = $rootSchema;
        foreach (explode('/', substr($reference, 2)) as $segment) {
            $segment = str_replace(['~1', '~0'], ['/', '~'], $segment);
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return is_array($value) ? $value : null;
    }
}
