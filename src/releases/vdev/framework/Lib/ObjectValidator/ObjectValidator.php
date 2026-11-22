<?php

namespace YTassy\Tycms\Lib\ObjectValidator;

use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Exceptions\TechException;

/**
 * Validates decoded JSON data against the framework's small, strict schema subset.
 *
 * Schemas are trusted PHP arrays. They must never be supplied by a client.
 * Unless explicitly set to true, additionalProperties is false for object schemas.
 */
final class ObjectValidator extends BaseApplicationItem
{
    private const DEFAULT_MAX_DEPTH = 64;
    private const DEFAULT_MAX_ERRORS = 100;

    /** @var array<string,ObjectValidatorFormat> */
    private array $formats = [];

    /** @var array<string,ObjectValidatorNormalizer> */
    private array $normalizers = [];

    public function __construct(
        BaseApplicationItem|Application $app,
        private readonly int $maxDepth = self::DEFAULT_MAX_DEPTH,
        private readonly int $maxErrors = self::DEFAULT_MAX_ERRORS,
    ) {
        parent::__construct($app);

        if ($this->maxDepth < 1 || $this->maxErrors < 1) {
            throw new TechException('ObjectValidator limits must be greater than zero.');
        }

        $this->registerStandardFormats();
        $this->registerStandardNormalizers();
    }

    /**
     * Registers a trusted, server-side format validator.
     *
     * A duplicate name is a programming error: a plugin must not silently replace
     * a format registered by another extension.
     */
    public function registerFormat(string $name, ObjectValidatorFormat $format): void
    {
        $name = trim($name);

        if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/', $name) !== 1) {
            throw new TechException("Invalid ObjectValidator format name: $name");
        }

        if (isset($this->formats[$name])) {
            throw new TechException("ObjectValidator format '$name' is already registered.");
        }

        $this->formats[$name] = $format;
    }

    public function hasFormat(string $name): bool
    {
        return isset($this->formats[$name]);
    }

    /** @return list<string> */
    public function getFormatNames(): array
    {
        return array_keys($this->formats);
    }

    /**
     * Registers a trusted, server-side normalizer.
     *
     * A normalizer may transform a value but must never make an ACL decision.
     */
    public function registerNormalizer(string $name, ObjectValidatorNormalizer $normalizer): void
    {
        $name = trim($name);

        if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/', $name) !== 1) {
            throw new TechException("Invalid ObjectValidator normalizer name: $name");
        }

        if (isset($this->normalizers[$name])) {
            throw new TechException("ObjectValidator normalizer '$name' is already registered.");
        }

        $this->normalizers[$name] = $normalizer;
    }

    public function hasNormalizer(string $name): bool
    {
        return isset($this->normalizers[$name]);
    }

    /** @return list<string> */
    public function getNormalizerNames(): array
    {
        return array_keys($this->normalizers);
    }

    /**
     * Validates data produced by json_decode($json, false).
     *
     * The second argument can be a direct schema or a legacy document shaped as:
     * ['schemas_ref' => [...], 'schema' => [...]]. The optional third argument
     * accepts named local references shared by several schemas.
     *
     * @param array<string,mixed>|object $schema
     * @param array<string,array<string,mixed>|object> $schemas
     */
    public function validate(mixed $object, array|object $schema, array $schemas = []): ObjectValidationResult
    {
        [$schema, $references] = $this->prepareSchema($schema, $schemas);

        $errors = [];
        $this->validateValue($object, $schema, $references, [], 0, $object, $errors);

        return new ObjectValidationResult($object, $errors);
    }

    /**
     * Creates a normalized copy of the decoded JSON data, then validates it.
     *
     * A normalizer is selected by the trusted "normalizer" (or ordered
     * "normalizers") schema keyword. It is always run before validation.
     *
     * @param array<string,mixed>|object $schema
     * @param array<string,array<string,mixed>|object> $schemas
     */
    public function normalizeAndValidate(mixed $object, array|object $schema, array $schemas = []): ObjectValidationResult
    {
        [$schema, $references] = $this->prepareSchema($schema, $schemas);
        $normalizedObject = $this->normaliseValue($this->copyJsonValue($object), $schema, $references, [], 0);

        $errors = [];
        $this->validateValue($normalizedObject, $schema, $references, [], 0, $normalizedObject, $errors);

        return new ObjectValidationResult($normalizedObject, $errors);
    }

    /**
     * Copies the stdClass/array tree returned by json_decode($json, false).
     *
     * Normalizers only receive this copy, so a faulty custom normalizer cannot
     * mutate the input payload by reference.
     */
    private function copyJsonValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $copy = [];
            foreach ($value as $key => $item) {
                $copy[$key] = $this->copyJsonValue($item);
            }

            return $copy;
        }

        if (is_object($value)) {
            $copy = new \stdClass();
            foreach (get_object_vars($value) as $propertyName => $propertyValue) {
                $copy->{$propertyName} = $this->copyJsonValue($propertyValue);
            }

            return $copy;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,array<string,mixed>> $references
     * @param list<string|int> $path
     */
    private function normaliseValue(
        mixed $value,
        array $schema,
        array $references,
        array $path,
        int $depth,
    ): mixed {
        if ($depth > $this->maxDepth) {
            return $value;
        }

        if (array_key_exists('$ref', $schema)) {
            $reference = $schema['$ref'];
            if (!is_string($reference)) {
                $this->invalidSchema('$ref must be a string.');
            }

            $referenceName = $this->resolveReferenceName($reference, $references);
            $value = $this->normaliseValue($value, $references[$referenceName], $references, $path, $depth + 1);
        }

        if (array_key_exists('allOf', $schema)) {
            foreach ($this->normaliseSchemaList($schema['allOf'], 'allOf') as $subSchema) {
                $value = $this->normaliseValue($value, $subSchema, $references, $path, $depth + 1);
            }
        }

        $value = $this->applyNormalizers($value, $schema, $path);

        if (array_key_exists('anyOf', $schema)) {
            $matchingSchema = $this->getMatchingSchema($value, $schema['anyOf'], 'anyOf', $references, $path, $depth);
            if ($matchingSchema !== null) {
                $value = $this->normaliseValue($value, $matchingSchema, $references, $path, $depth + 1);
            }
        }

        if (array_key_exists('oneOf', $schema)) {
            $matchingSchema = $this->getMatchingSchema($value, $schema['oneOf'], 'oneOf', $references, $path, $depth, true);
            if ($matchingSchema !== null) {
                $value = $this->normaliseValue($value, $matchingSchema, $references, $path, $depth + 1);
            }
        }

        if (is_array($value) && array_key_exists('items', $schema)) {
            $itemsSchema = $this->normaliseSchema($schema['items'], 'items');
            foreach ($value as $index => $item) {
                $value[$index] = $this->normaliseValue($item, $itemsSchema, $references, [...$path, $index], $depth + 1);
            }
        }

        if (!is_object($value) || !$this->hasObjectConstraints($schema)) {
            return $value;
        }

        $normalizedObject = new \stdClass();
        $propertySchemas = array_key_exists('properties', $schema)
            ? $this->normaliseSchemaMap($schema['properties'], 'properties')
            : [];
        $additionalProperties = $schema['additionalProperties'] ?? false;

        foreach (get_object_vars($value) as $propertyName => $propertyValue) {
            $propertyName = (string) $propertyName;
            if (isset($propertySchemas[$propertyName])) {
                $normalizedObject->{$propertyName} = $this->normaliseValue(
                    $propertyValue,
                    $propertySchemas[$propertyName],
                    $references,
                    [...$path, $propertyName],
                    $depth + 1,
                );
                continue;
            }

            if (is_array($additionalProperties) || is_object($additionalProperties)) {
                $normalizedObject->{$propertyName} = $this->normaliseValue(
                    $propertyValue,
                    $this->normaliseSchema($additionalProperties, 'additionalProperties'),
                    $references,
                    [...$path, $propertyName],
                    $depth + 1,
                );
                continue;
            }

            $normalizedObject->{$propertyName} = $propertyValue;
        }

        return $normalizedObject;
    }

    /**
     * @param list<string|int> $path
     * @param array<string,mixed> $schema
     */
    private function applyNormalizers(mixed $value, array $schema, array $path): mixed
    {
        foreach ($this->getNormalizerNamesFromSchema($schema) as $normalizerName) {
            $normalizer = $this->normalizers[$normalizerName] ?? null;
            if ($normalizer === null) {
                $this->invalidSchema("Unknown ObjectValidator normalizer '$normalizerName'.");
            }

            $value = $normalizer->normalize(
                $value,
                new ObjectValidatorNormalizerContext($normalizerName, $this->toJsonPointer($path), $schema),
            );
        }

        return $value;
    }

    /**
     * @param mixed $schemaList
     * @param array<string,array<string,mixed>> $references
     * @param list<string|int> $path
     */
    private function getMatchingSchema(
        mixed $value,
        mixed $schemaList,
        string $keyword,
        array $references,
        array $path,
        int $depth,
        bool $requireSingleMatch = false,
    ): ?array {
        $matches = [];
        foreach ($this->normaliseSchemaList($schemaList, $keyword) as $subSchema) {
            $branchErrors = [];
            $this->validateValue($value, $subSchema, $references, $path, $depth + 1, $value, $branchErrors);
            if ($branchErrors === []) {
                $matches[] = $subSchema;
                if (!$requireSingleMatch) {
                    return $subSchema;
                }
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param array<string,mixed>|object $schema
     * @param array<string,array<string,mixed>|object> $schemas
     * @return array{0:array<string,mixed>,1:array<string,array<string,mixed>>}
     */
    private function prepareSchema(array|object $schema, array $schemas): array
    {
        $schema = $this->normaliseSchema($schema, 'root schema');
        $references = $this->normaliseReferences($schemas);

        foreach (['schemas_ref', '$defs', 'definitions'] as $definitionsKey) {
            if (!isset($schema[$definitionsKey])) {
                continue;
            }

            $definitions = $this->normaliseSchemaMap($schema[$definitionsKey], "$definitionsKey definitions");
            $references = $this->addReferences($references, $definitions);
        }

        // Compatibility with the shape exported by the former JavaScript editor.
        if (isset($schema['schemas_ref']) && isset($schema['schema'])) {
            $schema = $this->normaliseSchema($schema['schema'], 'document schema');
        }

        return [$schema, $references];
    }

    /**
     * @param array<string,mixed> $schema
     * @return list<string>
     */
    private function getNormalizerNamesFromSchema(array $schema): array
    {
        if (array_key_exists('normalizer', $schema) && array_key_exists('normalizers', $schema)) {
            $this->invalidSchema('normalizer and normalizers cannot be used together.');
        }

        if (array_key_exists('normalizer', $schema)) {
            if (!is_string($schema['normalizer'])) {
                $this->invalidSchema('normalizer must be a string.');
            }

            return [$schema['normalizer']];
        }

        if (!array_key_exists('normalizers', $schema)) {
            return [];
        }

        $normalizers = $schema['normalizers'];
        if (!is_array($normalizers) || $normalizers === [] || !array_is_list($normalizers)) {
            $this->invalidSchema('normalizers must be a non-empty list of strings.');
        }

        foreach ($normalizers as $normalizerName) {
            if (!is_string($normalizerName)) {
                $this->invalidSchema('normalizers must be a non-empty list of strings.');
            }
        }

        return $normalizers;
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,array<string,mixed>> $references
     * @param list<string|int> $path
     * @param list<ObjectValidationError> $errors
     */
    private function validateValue(
        mixed $value,
        array $schema,
        array $references,
        array $path,
        int $depth,
        mixed $rootData,
        array &$errors,
    ): void {
        if (count($errors) >= $this->maxErrors) {
            return;
        }

        if ($depth > $this->maxDepth) {
            $this->addError(
                $errors,
                $path,
                'maxDepth',
                "La profondeur maximale de validation ({$this->maxDepth}) est dépassée.",
                $this->maxDepth,
                $depth,
            );
            return;
        }

        if (array_key_exists('$ref', $schema)) {
            $reference = $schema['$ref'];
            if (!is_string($reference)) {
                $this->invalidSchema('$ref must be a string.');
            }

            $referenceName = $this->resolveReferenceName($reference, $references);
            $this->validateValue(
                $value,
                $references[$referenceName],
                $references,
                $path,
                $depth + 1,
                $rootData,
                $errors,
            );
        }

        if (array_key_exists('allOf', $schema)) {
            foreach ($this->normaliseSchemaList($schema['allOf'], 'allOf') as $subSchema) {
                $this->validateValue($value, $subSchema, $references, $path, $depth + 1, $rootData, $errors);
            }
        }

        if (array_key_exists('anyOf', $schema)) {
            $isValid = false;
            foreach ($this->normaliseSchemaList($schema['anyOf'], 'anyOf') as $subSchema) {
                $branchErrors = [];
                $this->validateValue($value, $subSchema, $references, $path, $depth + 1, $rootData, $branchErrors);
                if ($branchErrors === []) {
                    $isValid = true;
                    break;
                }
            }

            if (!$isValid) {
                $this->addError($errors, $path, 'anyOf', 'La valeur ne correspond à aucune des alternatives autorisées.');
            }
        }

        if (array_key_exists('oneOf', $schema)) {
            $matches = 0;
            foreach ($this->normaliseSchemaList($schema['oneOf'], 'oneOf') as $subSchema) {
                $branchErrors = [];
                $this->validateValue($value, $subSchema, $references, $path, $depth + 1, $rootData, $branchErrors);
                if ($branchErrors === []) {
                    ++$matches;
                }
            }

            if ($matches !== 1) {
                $this->addError($errors, $path, 'oneOf', 'La valeur doit correspondre à une seule alternative.', 1, $matches);
            }
        }

        if (array_key_exists('not', $schema)) {
            $branchErrors = [];
            $this->validateValue(
                $value,
                $this->normaliseSchema($schema['not'], 'not'),
                $references,
                $path,
                $depth + 1,
                $rootData,
                $branchErrors,
            );

            if ($branchErrors === []) {
                $this->addError($errors, $path, 'not', 'La valeur correspond à un schéma interdit.');
            }
        }

        $typeIsValid = true;
        if (array_key_exists('type', $schema)) {
            $expectedTypes = $this->normaliseTypes($schema['type']);
            $typeIsValid = false;
            foreach ($expectedTypes as $type) {
                if ($this->isType($value, $type)) {
                    $typeIsValid = true;
                    break;
                }
            }

            if (!$typeIsValid) {
                $this->addError(
                    $errors,
                    $path,
                    'type',
                    'Le type de la valeur est invalide.',
                    count($expectedTypes) === 1 ? $expectedTypes[0] : $expectedTypes,
                    $this->getValueType($value),
                );
                return;
            }
        }

        if (array_key_exists('const', $schema) && !$this->valuesEqual($value, $schema['const'])) {
            $this->addError($errors, $path, 'const', 'La valeur ne correspond pas à la constante attendue.', $schema['const']);
        }

        if (array_key_exists('enum', $schema)) {
            if (!is_array($schema['enum']) || $schema['enum'] === []) {
                $this->invalidSchema('enum must be a non-empty array.');
            }

            $isAllowed = false;
            foreach ($schema['enum'] as $allowedValue) {
                if ($this->valuesEqual($value, $allowedValue)) {
                    $isAllowed = true;
                    break;
                }
            }

            if (!$isAllowed) {
                $this->addError($errors, $path, 'enum', 'La valeur ne fait pas partie de la liste autorisée.', $schema['enum']);
            }
        }

        if (is_string($value)) {
            $this->validateString($value, $schema, $path, $errors);
        }

        if ($this->isNumber($value)) {
            $this->validateNumber($value, $schema, $path, $errors);
        }

        if (is_array($value)) {
            $this->validateArray($value, $schema, $references, $path, $depth, $rootData, $errors);
        }

        if (is_object($value)) {
            $this->validateObject($value, $schema, $references, $path, $depth, $rootData, $errors);
        }

        if (array_key_exists('format', $schema) && $typeIsValid) {
            $this->validateFormat($value, $schema, $path, $rootData, $errors);
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @param list<string|int> $path
     * @param list<ObjectValidationError> $errors
     */
    private function validateString(string $value, array $schema, array $path, array &$errors): void
    {
        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);

        foreach (['minLength' => 'minimum', 'maxLength' => 'maximum'] as $keyword => $direction) {
            if (!array_key_exists($keyword, $schema)) {
                continue;
            }

            $limit = $this->normaliseNonNegativeInteger($schema[$keyword], $keyword);
            $isValid = $direction === 'minimum' ? $length >= $limit : $length <= $limit;
            if (!$isValid) {
                $message = $direction === 'minimum'
                    ? "Le texte doit contenir au moins $limit caractère(s)."
                    : "Le texte ne doit pas dépasser $limit caractère(s).";
                $this->addError($errors, $path, $keyword, $message, $limit, $length);
            }
        }

        if (array_key_exists('maxBytes', $schema)) {
            $limit = $this->normaliseNonNegativeInteger($schema['maxBytes'], 'maxBytes');
            $byteLength = strlen($value);
            if ($byteLength > $limit) {
                $this->addError(
                    $errors,
                    $path,
                    'maxBytes',
                    "Le texte ne doit pas dépasser $limit octet(s).",
                    $limit,
                    $byteLength,
                );
            }
        }

        if (!array_key_exists('pattern', $schema)) {
            return;
        }

        if (!is_string($schema['pattern'])) {
            $this->invalidSchema('pattern must be a string.');
        }

        $pattern = '~' . str_replace('~', '\\~', $schema['pattern']) . '~u';
        $matches = @preg_match($pattern, $value);
        if ($matches === false) {
            $this->invalidSchema('pattern is not a valid PCRE expression.');
        }

        if ($matches !== 1) {
            $this->addError($errors, $path, 'pattern', 'Le format du texte est invalide.');
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @param list<string|int> $path
     * @param list<ObjectValidationError> $errors
     */
    private function validateNumber(int|float $value, array $schema, array $path, array &$errors): void
    {
        foreach (['minimum' => '>=', 'maximum' => '<=', 'exclusiveMinimum' => '>', 'exclusiveMaximum' => '<'] as $keyword => $operator) {
            if (!array_key_exists($keyword, $schema)) {
                continue;
            }

            if (!$this->isNumber($schema[$keyword])) {
                $this->invalidSchema("$keyword must be a number.");
            }

            $limit = $schema[$keyword];
            $isValid = match ($operator) {
                '>=' => $value >= $limit,
                '<=' => $value <= $limit,
                '>' => $value > $limit,
                '<' => $value < $limit,
            };

            if (!$isValid) {
                $this->addError($errors, $path, $keyword, 'La valeur numérique est hors des limites autorisées.', $limit, $value);
            }
        }

        if (!array_key_exists('multipleOf', $schema)) {
            return;
        }

        if (!$this->isNumber($schema['multipleOf']) || $schema['multipleOf'] <= 0) {
            $this->invalidSchema('multipleOf must be a number greater than zero.');
        }

        $multipleOf = $schema['multipleOf'];
        $ratio = $value / $multipleOf;
        if (abs($ratio - round($ratio)) > 1e-12) {
            $this->addError($errors, $path, 'multipleOf', 'La valeur numérique n’est pas un multiple autorisé.', $multipleOf, $value);
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,array<string,mixed>> $references
     * @param list<string|int> $path
     * @param list<ObjectValidationError> $errors
     */
    private function validateArray(
        array $value,
        array $schema,
        array $references,
        array $path,
        int $depth,
        mixed $rootData,
        array &$errors,
    ): void {
        $count = count($value);
        foreach (['minItems' => 'minimum', 'maxItems' => 'maximum'] as $keyword => $direction) {
            if (!array_key_exists($keyword, $schema)) {
                continue;
            }

            $limit = $this->normaliseNonNegativeInteger($schema[$keyword], $keyword);
            $isValid = $direction === 'minimum' ? $count >= $limit : $count <= $limit;
            if (!$isValid) {
                $message = $direction === 'minimum'
                    ? "La liste doit contenir au moins $limit élément(s)."
                    : "La liste ne doit pas dépasser $limit élément(s).";
                $this->addError($errors, $path, $keyword, $message, $limit, $count);
            }
        }

        if (($schema['uniqueItems'] ?? false) === true && !$this->hasUniqueItems($value)) {
            $this->addError($errors, $path, 'uniqueItems', 'Les éléments de la liste doivent être uniques.');
        }

        if (!array_key_exists('items', $schema)) {
            return;
        }

        $itemsSchema = $this->normaliseSchema($schema['items'], 'items');
        foreach ($value as $index => $item) {
            $this->validateValue($item, $itemsSchema, $references, [...$path, $index], $depth + 1, $rootData, $errors);
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @param array<string,array<string,mixed>> $references
     * @param list<string|int> $path
     * @param list<ObjectValidationError> $errors
     */
    private function validateObject(
        object $value,
        array $schema,
        array $references,
        array $path,
        int $depth,
        mixed $rootData,
        array &$errors,
    ): void {
        if (!$this->hasObjectConstraints($schema)) {
            return;
        }

        $properties = get_object_vars($value);
        $propertyCount = count($properties);

        foreach (['minProperties' => 'minimum', 'maxProperties' => 'maximum'] as $keyword => $direction) {
            if (!array_key_exists($keyword, $schema)) {
                continue;
            }

            $limit = $this->normaliseNonNegativeInteger($schema[$keyword], $keyword);
            $isValid = $direction === 'minimum' ? $propertyCount >= $limit : $propertyCount <= $limit;
            if (!$isValid) {
                $message = $direction === 'minimum'
                    ? "L’objet doit contenir au moins $limit propriété(s)."
                    : "L’objet ne doit pas dépasser $limit propriété(s).";
                $this->addError($errors, $path, $keyword, $message, $limit, $propertyCount);
            }
        }

        $required = $schema['required'] ?? [];
        if (!is_array($required)) {
            $this->invalidSchema('required must be an array.');
        }

        foreach ($required as $propertyName) {
            if (!is_string($propertyName)) {
                $this->invalidSchema('required may only contain property names.');
            }

            if (!array_key_exists($propertyName, $properties)) {
                $this->addError(
                    $errors,
                    [...$path, $propertyName],
                    'required',
                    'La propriété est obligatoire.',
                    'present',
                    'missing',
                );
            }
        }

        $propertySchemas = [];
        if (array_key_exists('properties', $schema)) {
            $propertySchemas = $this->normaliseSchemaMap($schema['properties'], 'properties');
        }

        $additionalProperties = $schema['additionalProperties'] ?? false;
        if (!is_bool($additionalProperties) && !is_array($additionalProperties) && !is_object($additionalProperties)) {
            $this->invalidSchema('additionalProperties must be a boolean or a schema.');
        }

        foreach ($properties as $propertyName => $propertyValue) {
            $propertyName = (string) $propertyName;
            $propertyPath = [...$path, $propertyName];

            if (isset($propertySchemas[$propertyName])) {
                $this->validateValue(
                    $propertyValue,
                    $propertySchemas[$propertyName],
                    $references,
                    $propertyPath,
                    $depth + 1,
                    $rootData,
                    $errors,
                );
                continue;
            }

            if ($additionalProperties === false) {
                $this->addError($errors, $propertyPath, 'additionalProperties', 'Cette propriété n’est pas autorisée.');
                continue;
            }

            if ($additionalProperties !== true) {
                $this->validateValue(
                    $propertyValue,
                    $this->normaliseSchema($additionalProperties, 'additionalProperties'),
                    $references,
                    $propertyPath,
                    $depth + 1,
                    $rootData,
                    $errors,
                );
            }
        }
    }

    /**
     * @param array<string,mixed> $schema
     * @param list<string|int> $path
     * @param list<ObjectValidationError> $errors
     */
    private function validateFormat(mixed $value, array $schema, array $path, mixed $rootData, array &$errors): void
    {
        if (!is_string($schema['format'])) {
            $this->invalidSchema('format must be a string.');
        }

        $formatName = $schema['format'];
        $format = $this->formats[$formatName] ?? null;
        if ($format === null) {
            $this->invalidSchema("Unknown ObjectValidator format '$formatName'.");
        }

        $result = $format->validate(
            $value,
            new ObjectValidatorFormatContext($formatName, $this->toJsonPointer($path), $rootData, $schema),
        );

        if (is_bool($result)) {
            $result = $result
                ? ObjectValidatorFormatResult::valid()
                : ObjectValidatorFormatResult::invalid();
        }

        if (!$result instanceof ObjectValidatorFormatResult) {
            throw new TechException("ObjectValidator format '$formatName' returned an invalid result.");
        }

        if (!$result->isValid()) {
            $this->addError(
                $errors,
                $path,
                'format',
                $result->getMessage() ?? "La valeur ne respecte pas le format '$formatName'.",
                $formatName,
                $this->getValueType($value),
            );
        }
    }

    /**
     * @param list<ObjectValidationError> $errors
     * @param list<string|int> $path
     */
    private function addError(
        array &$errors,
        array $path,
        string $keyword,
        string $message,
        mixed $expected = null,
        mixed $actual = null,
    ): void {
        if (count($errors) >= $this->maxErrors) {
            return;
        }

        $errors[] = new ObjectValidationError(
            $this->toJsonPointer($path),
            $keyword,
            $message,
            $expected,
            $actual,
        );
    }

    /** @param array<string,array<string,mixed>> $references */
    private function resolveReferenceName(string $reference, array $references): string
    {
        if (str_contains($reference, '://')) {
            $this->invalidSchema('External $ref values are not allowed.');
        }

        $candidates = [$reference, ltrim($reference, '/')];
        foreach (['#/$defs/', '#/definitions/', '#/schemas_ref/'] as $prefix) {
            if (str_starts_with($reference, $prefix)) {
                $candidates[] = substr($reference, strlen($prefix));
            }
        }

        foreach ($candidates as $candidate) {
            if (isset($references[$candidate])) {
                return $candidate;
            }
        }

        $this->invalidSchema("Unknown local schema reference '$reference'.");
    }

    /**
     * @param array<string,mixed>|object $schema
     * @return array<string,mixed>
     */
    private function normaliseSchema(array|object $schema, string $location): array
    {
        if (is_object($schema)) {
            $schema = get_object_vars($schema);
        }

        if ($schema !== [] && array_is_list($schema)) {
            $this->invalidSchema("$location must be an associative array.");
        }

        return $schema;
    }

    /**
     * @param array<string,array<string,mixed>|object> $schemas
     * @return array<string,array<string,mixed>>
     */
    private function normaliseReferences(array $schemas): array
    {
        $references = [];
        foreach ($schemas as $name => $schema) {
            if (!is_string($name)) {
                $this->invalidSchema('Local schema references must have string names.');
            }
            $references = $this->addReferences($references, [$name => $schema]);
        }

        return $references;
    }

    /**
     * @param mixed $schemaMap
     * @return array<string,array<string,mixed>>
     */
    private function normaliseSchemaMap(mixed $schemaMap, string $location): array
    {
        if (is_object($schemaMap)) {
            $schemaMap = get_object_vars($schemaMap);
        }

        if (!is_array($schemaMap) || ($schemaMap !== [] && array_is_list($schemaMap))) {
            $this->invalidSchema("$location must be an associative array.");
        }

        $result = [];
        foreach ($schemaMap as $name => $schema) {
            if (!is_string($name)) {
                $this->invalidSchema("$location keys must be strings.");
            }
            $result[$name] = $this->normaliseSchema($schema, "$location.$name");
        }

        return $result;
    }

    /**
     * @param array<string,array<string,mixed>> $references
     * @param array<string,array<string,mixed>> $newReferences
     * @return array<string,array<string,mixed>>
     */
    private function addReferences(array $references, array $newReferences): array
    {
        foreach ($newReferences as $name => $schema) {
            $normalisedName = ltrim($name, '/');
            if ($normalisedName === '') {
                $this->invalidSchema('A local schema reference cannot be empty.');
            }
            if (isset($references[$normalisedName])) {
                $this->invalidSchema("The local schema reference '$normalisedName' is declared twice.");
            }

            $references[$normalisedName] = $schema;
            if ($name !== $normalisedName) {
                $references[$name] = $schema;
            }
        }

        return $references;
    }

    /**
     * @param mixed $schemas
     * @return list<array<string,mixed>>
     */
    private function normaliseSchemaList(mixed $schemas, string $keyword): array
    {
        if (!is_array($schemas) || $schemas === []) {
            $this->invalidSchema("$keyword must be a non-empty array of schemas.");
        }

        $result = [];
        foreach ($schemas as $schema) {
            $result[] = $this->normaliseSchema($schema, $keyword);
        }

        return $result;
    }

    /** @return list<string> */
    private function normaliseTypes(mixed $types): array
    {
        $types = is_array($types) ? $types : [$types];
        if ($types === []) {
            $this->invalidSchema('type must be a string or a non-empty array of strings.');
        }

        $allowedTypes = ['null', 'boolean', 'object', 'array', 'number', 'integer', 'string'];
        foreach ($types as $type) {
            if (!is_string($type) || !in_array($type, $allowedTypes, true)) {
                $this->invalidSchema('type contains an unsupported JSON type.');
            }
        }

        return array_values(array_unique($types));
    }

    private function normaliseNonNegativeInteger(mixed $value, string $keyword): int
    {
        if (!is_int($value) || $value < 0) {
            $this->invalidSchema("$keyword must be a non-negative integer.");
        }

        return $value;
    }

    private function isType(mixed $value, string $type): bool
    {
        return match ($type) {
            'null' => $value === null,
            'boolean' => is_bool($value),
            'object' => is_object($value),
            'array' => is_array($value),
            'number' => $this->isNumber($value),
            'integer' => is_int($value),
            'string' => is_string($value),
        };
    }

    private function isNumber(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value));
    }

    /** @param array<string,mixed> $schema */
    private function hasObjectConstraints(array $schema): bool
    {
        foreach (['type', 'minProperties', 'maxProperties', 'required', 'properties', 'additionalProperties'] as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                return true;
            }
        }

        return false;
    }

    private function getValueType(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => 'array',
            is_object($value) => 'object',
            default => get_debug_type($value),
        };
    }

    private function valuesEqual(mixed $left, mixed $right): bool
    {
        if ($this->isNumber($left) && $this->isNumber($right)) {
            return $left == $right;
        }

        if (is_array($left) && is_array($right)) {
            if (count($left) !== count($right) || array_keys($left) !== array_keys($right)) {
                return false;
            }
            foreach ($left as $key => $item) {
                if (!$this->valuesEqual($item, $right[$key])) {
                    return false;
                }
            }
            return true;
        }

        if (is_object($left) && is_object($right)) {
            return $this->valuesEqual(get_object_vars($left), get_object_vars($right));
        }

        if (is_object($left) && is_array($right)) {
            return $this->valuesEqual(get_object_vars($left), $right);
        }

        if (is_array($left) && is_object($right)) {
            return $this->valuesEqual($left, get_object_vars($right));
        }

        return $left === $right;
    }

    /** @param array<int,mixed> $items */
    private function hasUniqueItems(array $items): bool
    {
        $count = count($items);
        for ($left = 0; $left < $count; ++$left) {
            for ($right = $left + 1; $right < $count; ++$right) {
                if ($this->valuesEqual($items[$left], $items[$right])) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param list<string|int> $path */
    private function toJsonPointer(array $path): string
    {
        return implode('', array_map(
            static fn (string|int $segment): string => '/' . str_replace(['~', '/'], ['~0', '~1'], (string) $segment),
            $path,
        ));
    }

    private function invalidSchema(string $message): never
    {
        throw new TechException("Invalid ObjectValidator schema: $message");
    }

    private function registerStandardFormats(): void
    {
        $this->registerFormat('date', new CallbackObjectValidatorFormat(
            static fn (mixed $value): bool => is_string($value) && self::isDate($value),
        ));
        $this->registerFormat('time', new CallbackObjectValidatorFormat(
            static fn (mixed $value): bool => is_string($value)
                && preg_match('/^(?:[01]\\d|2[0-3]):[0-5]\\d:[0-5]\\d(?:\\.\\d+)?(?:Z|[+-](?:[01]\\d|2[0-3]):[0-5]\\d)$/D', $value) === 1,
        ));
        $this->registerFormat('date-time', new CallbackObjectValidatorFormat(
            static fn (mixed $value): bool => is_string($value) && self::isDateTime($value),
        ));
        $this->registerFormat('email', new CallbackObjectValidatorFormat(
            static fn (mixed $value): bool => is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) !== false,
        ));
        $this->registerFormat('uuid', new CallbackObjectValidatorFormat(
            static fn (mixed $value): bool => is_string($value)
                && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/Di', $value) === 1,
        ));
        $this->registerFormat('ipv4', new CallbackObjectValidatorFormat(
            static fn (mixed $value): bool => is_string($value)
                && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false,
        ));
        $this->registerFormat('ipv6', new CallbackObjectValidatorFormat(
            static fn (mixed $value): bool => is_string($value)
                && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false,
        ));
        $this->registerFormat('hostname', new CallbackObjectValidatorFormat(
            static fn (mixed $value): bool => is_string($value)
                && filter_var($value, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false,
        ));
        $this->registerFormat('uri', new CallbackObjectValidatorFormat(
            static fn (mixed $value): bool => is_string($value)
                && preg_match('/^[A-Za-z][A-Za-z0-9+.-]*:[^\s]*$/D', $value) === 1,
        ));
        $this->registerFormat('duration', new CallbackObjectValidatorFormat(
            static fn (mixed $value): bool => is_string($value)
                && preg_match('/^P(?=\\d|T\\d)(?:\\d+Y)?(?:\\d+M)?(?:\\d+D)?(?:T(?:\\d+H)?(?:\\d+M)?(?:\\d+(?:\\.\\d+)?S)?)?$/D', $value) === 1,
        ));
        $this->registerFormat('json-pointer', new CallbackObjectValidatorFormat(
            static fn (mixed $value): bool => is_string($value)
                && preg_match('/^(?:\\/(?:[^~\\/]|~[01])*)*$/D', $value) === 1,
        ));
    }

    private function registerStandardNormalizers(): void
    {
        $this->registerNormalizer('html-sanitizer', new HtmlSanitizerNormalizer());
    }

    private static function isDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))
            && $date->format('Y-m-d') === $value;
    }

    private static function isDateTime(string $value): bool
    {
        if (preg_match('/^\\d{4}-\\d{2}-\\d{2}T(?:[01]\\d|2[0-3]):[0-5]\\d:[0-5]\\d(?:\\.\\d+)?(?:Z|[+-](?:[01]\\d|2[0-3]):[0-5]\\d)$/D', $value) !== 1) {
            return false;
        }

        if (!self::isDate(substr($value, 0, 10))) {
            return false;
        }

        try {
            new \DateTimeImmutable($value);
            return true;
        } catch (\Exception) {
            return false;
        }
    }
}
