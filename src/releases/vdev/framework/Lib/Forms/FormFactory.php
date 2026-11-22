<?php

declare(strict_types=1);

namespace YTassy\Tycms\Lib\Forms;

use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Exceptions\TechException;

/**
 * Per-application registry for form field types.
 *
 * Extensions register their own type during onRegisterFormTypes(). The
 * factory is obtained through getSharedExtensionsClass(), so registrations do
 * not leak between applications or test cases.
 */
final class FormFactory extends BaseApplicationItem
{
    /** @var array<string,FormFieldType> */
    private array $types = [];

    public function __construct(BaseApplicationItem|Application $app)
    {
        parent::__construct($app);
        $this->registerCoreTypes();
    }

    public function registerType(FormFieldType $type): void
    {
        if (!str_contains($type->id, '.')) {
            throw new TechException("Form field type '{$type->id}' must be qualified by its extension.");
        }

        if (isset($this->types[$type->id])) {
            throw new TechException("Form field type '{$type->id}' is already registered.");
        }

        $this->types[$type->id] = $type;
    }

    public function hasType(string $id): bool
    {
        return isset($this->types[$id]);
    }

    public function getType(string $id): FormFieldType
    {
        return $this->types[$id]
            ?? throw new TechException("Unknown form field type '$id'.");
    }

    /**
     * Returns the default contributed by a field type when the form definition
     * does not explicitly provide a `default` value.
     *
     * @param array<string,mixed> $field
     */
    public function getImplicitDefaultForField(array $field): mixed
    {
        $type = $this->getFieldType($field);
        $this->assertFieldSpecificDefinition($field, $type);

        return $type->getImplicitDefault($field);
    }

    /**
     * Returns the trusted validation schema for one field value. The form
     * composes it with field-specific rules and the enclosing object schema.
     *
     * @param array<string,mixed> $field
     * @return array<string,mixed>|null
     */
    public function getValueSchemaForField(array $field): ?array
    {
        $type = $this->getFieldType($field);
        $this->assertFieldSpecificDefinition($field, $type);

        return $type->getValueSchema($field);
    }

    /** @return list<string> */
    public function getTypeIds(): array
    {
        return array_keys($this->types);
    }

    /**
     * @param array<string,mixed> $field
     * @return array<string,mixed>
     */
    public function prepareFieldForRia(array $field, FormOptionContext $context): array
    {
        $type = $this->getFieldType($field);
        $typeId = $type->id;
        $this->assertFieldSpecificDefinition($field, $type);
        $result = $field;
        unset($result['optionsProvider'], $result['computedProvider']);
        $result['component'] = $type->vueComponent;
        $fieldSubmittable = $field['submittable'] ?? true;
        if (!is_bool($fieldSubmittable)) {
            throw new TechException("The submittable property of form field '{$this->getFieldDebugName($field)}' must be a boolean.");
        }
        $result['submittable'] = $type->isSubmittable && $fieldSubmittable;

        $hasStaticOptions = array_key_exists('options', $field);
        $provider = $field['optionsProvider'] ?? null;
        if ($hasStaticOptions && $provider !== null) {
            throw new TechException("Form field '{$this->getFieldDebugName($field)}' cannot define both options and optionsProvider.");
        }

        if (($hasStaticOptions || $provider !== null) && !$type->acceptsOptions) {
            throw new TechException("Form field type '$typeId' does not accept options.");
        }

        if ($provider !== null) {
            if (!$provider instanceof FormOptionsProvider) {
                throw new TechException("The optionsProvider of field '{$this->getFieldDebugName($field)}' must implement FormOptionsProvider.");
            }

            $result['options'] = FormOption::normaliseList($provider->resolve($context));
        } elseif ($hasStaticOptions) {
            if (!is_array($field['options'])) {
                throw new TechException("The options of field '{$this->getFieldDebugName($field)}' must be an array.");
            }

            $result['options'] = FormOption::normaliseList($field['options']);
        } elseif ($type->acceptsOptions) {
            $defaultOptions = $type->getDefaultOptions($context);
            if ($defaultOptions !== null) {
                $result['options'] = FormOption::normaliseList($defaultOptions);
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $field */
    public function resolveComputedValue(array $field, FormComputedContext $context): mixed
    {
        $type = $this->getFieldType($field);
        if ($type->id !== 'core.computed') {
            throw new TechException("Form field '{$this->getFieldDebugName($field)}' is not computed.");
        }

        $this->assertFieldSpecificDefinition($field, $type);
        /** @var FormComputedProvider $provider */
        $provider = $field['computedProvider'];

        return $provider->compute($context);
    }

    private function registerCoreTypes(): void
    {
        foreach ([
            new FormFieldType('core.text', 'forms/text', implicitDefault: '', valueSchema: ['type' => 'string']),
            new FormFieldType('core.textarea', 'forms/textarea', implicitDefault: '', valueSchema: ['type' => 'string']),
            new FormFieldType('core.code', 'forms/code', implicitDefault: '', valueSchema: ['type' => 'string']),
            new FormFieldType(
                'core.computed',
                'forms/computed',
                isSubmittable: false,
                hasDefaultValue: false,
                implicitDefault: null,
                valueSchema: static fn (array $field): array => $field['schema'],
            ),
            new FormFieldType(
                'core.color',
                'forms/color',
                implicitDefault: '#000000',
                valueSchema: static fn (array $field): array => [
                    'type' => 'string',
                    'pattern' => ($field['allowAlpha'] ?? true) === true
                        ? '^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$'
                        : '^#[0-9A-Fa-f]{6}$',
                ],
            ),
            new FormFieldType(
                'core.number',
                'forms/number',
                implicitDefault: null,
                valueSchema: static fn (array $field): array => [
                    'type' => ($field['required'] ?? false) === true ? 'number' : ['number', 'null'],
                ],
            ),
            new FormFieldType('core.hidden', 'forms/hidden', implicitDefault: '', valueSchema: ['type' => 'string']),
            new FormFieldType('core.switch', 'forms/switch', implicitDefault: false, valueSchema: ['type' => 'boolean']),
            new FormFieldType('core.icon-picker', 'forms/icon-picker', implicitDefault: '', valueSchema: ['type' => 'string']),
            new FormFieldType('core.select', 'forms/select', acceptsOptions: true, implicitDefault: null),
            new FormFieldType('core.radio', 'forms/radio', acceptsOptions: true, implicitDefault: null),
            new FormFieldType('core.note', 'forms/note', hasValue: false),
            new FormFieldType(
                'core.json',
                'forms/json',
                implicitDefault: static fn (array $field): mixed => FormJsonSchemaDefault::fromField($field),
                valueSchema: static fn (array $field): array => $field['schema'],
            ),
        ] as $type) {
            $this->registerType($type);
        }
    }

    /** @param array<string,mixed> $field */
    private function getFieldDebugName(array $field): string
    {
        return is_string($field['name'] ?? null) ? $field['name'] : (string) ($field['type'] ?? 'unknown');
    }

    /** @param array<string,mixed> $field */
    private function getFieldType(array $field): FormFieldType
    {
        $typeId = $field['type'] ?? null;
        if (!is_string($typeId)) {
            throw new TechException('A form field must define a string type.');
        }

        return $this->getType($typeId);
    }

    /** @param array<string,mixed> $field */
    private function assertFieldSpecificDefinition(array $field, FormFieldType $type): void
    {
        if ($type->id !== 'core.computed' && array_key_exists('computedProvider', $field)) {
            throw new TechException("Only computed field '{$this->getFieldDebugName($field)}' may define computedProvider.");
        }

        if ($type->id === 'core.code') {
            $this->assertCodeSyntax($field, 'javascript');

            return;
        }

        if ($type->id === 'core.computed') {
            $fieldName = $this->getFieldDebugName($field);
            $provider = $field['computedProvider'] ?? null;
            if (!$provider instanceof FormComputedProvider) {
                throw new TechException("The computedProvider of computed field '$fieldName' must implement FormComputedProvider.");
            }

            if (array_key_exists('default', $field)) {
                throw new TechException("Computed field '$fieldName' cannot define a default value.");
            }

            if (!is_array($field['schema'] ?? null)) {
                throw new TechException("Computed field '$fieldName' must define an array schema.");
            }

            if (array_key_exists('syntax', $field)) {
                $this->assertCodeSyntax($field, null);
            }

            return;
        }

        if ($type->id === 'core.color') {
            $fieldName = $this->getFieldDebugName($field);

            if (array_key_exists('allowAlpha', $field) && !is_bool($field['allowAlpha'])) {
                throw new TechException("The allowAlpha property of color field '$fieldName' must be a boolean.");
            }

            if (!array_key_exists('palette', $field)) {
                return;
            }

            if (!is_array($field['palette']) || !array_is_list($field['palette'])) {
                throw new TechException("The palette property of color field '$fieldName' must be a list.");
            }

            foreach ($field['palette'] as $color) {
                if (!is_string($color) || preg_match('/^#[0-9A-Fa-f]{6}([0-9A-Fa-f]{2})?$/D', $color) !== 1) {
                    throw new TechException("The palette of color field '$fieldName' contains an invalid color.");
                }
            }

            return;
        }

        if ($type->id !== 'core.json') {
            return;
        }

        $schema = $field['schema'] ?? null;
        if (!is_array($schema)) {
            throw new TechException("The JSON field '{$this->getFieldDebugName($field)}' must define an array schema.");
        }
    }

    /** @param array<string,mixed> $field */
    private function assertCodeSyntax(array $field, ?string $default): void
    {
        $syntax = $field['syntax'] ?? $default;
        if (!is_string($syntax) || !in_array($syntax, ['javascript', 'js', 'html', 'css', 'scss', 'json', 'mermaid'], true)) {
            $fieldName = $this->getFieldDebugName($field);
            throw new TechException("The syntax property of field '$fieldName' must be javascript, js, html, css, scss, json or mermaid.");
        }
    }
}
