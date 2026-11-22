<?php

declare(strict_types=1);

namespace YTassy\Tycms\Lib\Forms;

use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Lib\ObjectValidator\ObjectValidationResult;
use YTassy\Tycms\TraitFrameworkSharedExtensions;

/**
 * Trusted server-side definition of one form.
 *
 * The definition may contain PHP-only option providers. getDefForRia() is the
 * boundary that resolves them and returns JSON-safe data for Vue.
 */
final class Form extends BaseApplicationItem
{
    use TraitFrameworkSharedExtensions;

    /** @var array<string,mixed> */
    private array $definition = [
        'id' => '',
        'version' => 1,
        'sections' => [],
    ];

    /**
     * @param array<string,mixed> $definition
     */
    public function __construct(BaseApplicationItem|Application $app, array $definition = [])
    {
        parent::__construct($app);

        if ($definition !== []) {
            $this->setDefinition($definition);
        }
    }

    /**
     * @param array<string,mixed> $definition
     */
    public static function fromArray(BaseApplicationItem|Application $app, array $definition): self
    {
        return new self($app, $definition);
    }

    public function setId(string $id): self
    {
        $this->assertIdentifier($id, 'form id');
        $this->definition['id'] = $id;

        return $this;
    }

    public function setLabel(?string $label): self
    {
        $this->definition['label'] = $label;

        return $this;
    }

    public function setDescription(?string $description): self
    {
        $this->definition['description'] = $description;

        return $this;
    }

    /**
     * Hides the tab bar when this form contains exactly one section.
     */
    public function setHideSingleSectionTab(bool $hide = true): self
    {
        $this->definition['hideSingleSectionTab'] = $hide;

        return $this;
    }

    /**
     * @param array<string,mixed> $options JSON-safe section options, for example ui.
     */
    public function addSection(string $id, string $label, array $options = []): self
    {
        $this->assertIdentifier($id, 'section id');
        $this->assertNoReservedKeys($options, ['id', 'label', 'fields'], 'section');

        foreach ($this->definition['sections'] as $section) {
            if ($section['id'] === $id) {
                throw new TechException("Form section '$id' is already defined.");
            }
        }

        $this->definition['sections'][] = [
            'id' => $id,
            'label' => $label,
            ...$options,
            'fields' => [],
        ];

        return $this;
    }

    /**
     * @param array<string,mixed> $options
     */
    public function addField(string $sectionId, string $name, string $type, array $options = []): self
    {
        $this->assertIdentifier($name, 'field name');
        $this->assertIdentifier($type, 'field type');
        $this->assertNoReservedKeys($options, ['name', 'type'], 'field');

        $sectionIndex = $this->findSectionIndex($sectionId);
        $this->assertFieldNameIsAvailable($name);

        $this->definition['sections'][$sectionIndex]['fields'][] = [
            'name' => $name,
            'type' => $type,
            ...$options,
        ];

        return $this;
    }

    /**
     * Adds a non-editable explanatory block. A note intentionally has no name
     * and therefore never appears in initialValues or a submitted payload.
     *
     * @param array<string,mixed> $options
     */
    public function addNote(string $sectionId, ?string $label = null, ?string $description = null, array $options = []): self
    {
        $this->assertNoReservedKeys($options, ['name', 'type', 'label', 'description'], 'note');
        $sectionIndex = $this->findSectionIndex($sectionId);

        $this->definition['sections'][$sectionIndex]['fields'][] = [
            'type' => 'core.note',
            'label' => $label,
            'description' => $description,
            ...$options,
        ];

        return $this;
    }

    /**
     * Returns the original trusted PHP definition. It can contain a
     * FormOptionsProvider and must never be returned to a browser.
     *
     * @return array<string,mixed>
     */
    public function getDefinition(): array
    {
        return $this->definition;
    }

    /**
     * Returns one initial value for each editable field.
     *
     * An explicit `default` takes precedence. Otherwise, the field type
     * contributes its implicit default. Informational fields, such as
     * core.note, are excluded.
     *
     * @return array<string,mixed>
     */
    public function getDefaultValues(): array
    {
        $factory = $this->formFactory;

        return $this->getDefaultValuesForFactory($factory);
    }

    /**
     * Builds the complete value set used to create or edit a record. Values
     * which are not fields of this form are deliberately ignored.
     *
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public function getInitialValues(array $values = []): array
    {
        $factory = $this->formFactory;

        return $this->getInitialValuesForFactory($factory, $values);
    }

    public function hasComputedFields(): bool
    {
        foreach ($this->definition['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                if (($field['type'] ?? null) === 'core.computed') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Produces the ObjectValidator schema for an entire submitted form.
     * Dynamic options are resolved server-side with the same trusted context as
     * the RIA definition, so a client cannot submit a value absent from a
     * select or radio list.
     *
     * @param array<string,mixed> $values
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function getSubmissionSchema(array $values = [], array $context = []): array
    {
        return $this->getValuesSchema($values, $context, false);
    }

    /**
     * Produces the trusted schema of the complete persisted form value set,
     * including values generated by computed fields.
     *
     * @param array<string,mixed> $values
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function getPersistenceSchema(array $values = [], array $context = []): array
    {
        return $this->getValuesSchema($values, $context, true);
    }

    /**
     * Validates a decoded JSON object against the current trusted definition.
     * Callers keep ACL and persistence decisions in their workflow.
     *
     * @param array<string,mixed> $context
     */
    public function validateSubmittedValues(object $values, array $context = []): ObjectValidationResult
    {
        return $this->objectValidator->normalizeAndValidate(
            $values,
            $this->getSubmissionSchema(get_object_vars($values), $context),
        );
    }

    /**
     * Validates the full value set that will be persisted, including computed
     * fields. Workflows call this through resolveComputedValues().
     *
     * @param array<string,mixed> $context
     */
    public function validatePersistedValues(object $values, array $context = []): ObjectValidationResult
    {
        return $this->objectValidator->normalizeAndValidate(
            $values,
            $this->getPersistenceSchema(get_object_vars($values), $context),
        );
    }

    /**
     * Resolves every core.computed field in declaration order, then validates
     * the complete value set before it is persisted. Providers are never run
     * while a form is materialized for Vue.
     *
     * @param array<string,mixed> $values Validated editable values, normally
     *        completed with getInitialValues().
     * @param array<string,mixed> $context Trusted workflow context.
     * @return array<string,mixed>
     */
    public function resolveComputedValues(array $values, array $context = []): array
    {
        if ($this->definition['id'] === '') {
            throw new TechException('A form id must be defined before computed values are resolved.');
        }

        $factory = $this->formFactory;
        $resolvedValues = $values;

        foreach ($this->definition['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $name = $field['name'] ?? null;
                $typeId = $field['type'] ?? null;
                if (!is_string($name) || $typeId !== 'core.computed') {
                    continue;
                }

                $resolvedValues[$name] = $factory->resolveComputedValue(
                    $field,
                    new FormComputedContext($this, $field, $resolvedValues, $context),
                );
            }
        }

        $validation = $this->validatePersistedValues((object) $resolvedValues, $context);
        if (!$validation->isValid()) {
            $message = $validation->getFirstError()?->message ?? 'invalid computed value.';
            throw new TechException("Computed form values are invalid: $message");
        }

        /** @var object $normalisedValues */
        $normalisedValues = $validation->value;

        return get_object_vars($normalisedValues);
    }

    /**
     * Replaces the form using the same array structure returned by getDefinition().
     *
     * @param array<string,mixed> $definition
     */
    public function setDefinition(array $definition): self
    {
        $id = $definition['id'] ?? null;
        if (!is_string($id)) {
            throw new TechException('A form definition must contain a string id.');
        }

        $this->assertIdentifier($id, 'form id');

        $version = $definition['version'] ?? 1;
        if (!is_int($version) || $version < 1) {
            throw new TechException('A form definition version must be a positive integer.');
        }

        $sections = $definition['sections'] ?? null;
        if (!is_array($sections) || !array_is_list($sections)) {
            throw new TechException('A form definition sections property must be a list.');
        }

        $this->definition = [
            ...$definition,
            'id' => $id,
            'version' => $version,
            'sections' => [],
        ];

        foreach ($sections as $section) {
            if (!is_array($section)) {
                throw new TechException('Each form section must be an array.');
            }

            $sectionId = $section['id'] ?? null;
            $sectionLabel = $section['label'] ?? '';
            if (!is_string($sectionId) || !is_string($sectionLabel)) {
                throw new TechException('Each form section must define string id and label properties.');
            }

            $fields = $section['fields'] ?? [];
            if (!is_array($fields) || !array_is_list($fields)) {
                throw new TechException("The '$sectionId' section fields property must be a list.");
            }

            $sectionOptions = $section;
            unset($sectionOptions['id'], $sectionOptions['label'], $sectionOptions['fields']);
            $this->addSection($sectionId, $sectionLabel, $sectionOptions);

            foreach ($fields as $field) {
                if (!is_array($field)) {
                    throw new TechException("Each field in section '$sectionId' must be an array.");
                }

                $type = $field['type'] ?? null;
                if (!is_string($type)) {
                    throw new TechException("Each field in section '$sectionId' must define a string type.");
                }

                $fieldOptions = $field;
                unset($fieldOptions['type'], $fieldOptions['name']);

                if ($type === 'core.note') {
                    unset($fieldOptions['label'], $fieldOptions['description']);
                    $this->addNote(
                        $sectionId,
                        $field['label'] ?? null,
                        $field['description'] ?? null,
                        $fieldOptions,
                    );
                    continue;
                }

                $name = $field['name'] ?? null;
                if (!is_string($name)) {
                    throw new TechException("The '$type' field in section '$sectionId' must define a string name.");
                }

                $this->addField($sectionId, $name, $type, $fieldOptions);
            }
        }

        return $this;
    }

    /**
     * Materializes the trusted definition for Vue. Option providers are run on
     * the server; callbacks, SQL and application objects can never cross this
     * boundary.
     *
     * @param array<string,mixed> $values Current values of the edited resource.
     * @param array<string,mixed> $context Extra trusted context for option providers.
     * @return array<string,mixed>
     */
    public function getDefForRia(array $values = [], array $context = []): array
    {
        if ($this->definition['id'] === '') {
            throw new TechException('A form id must be defined before it is rendered.');
        }

        $factory = $this->formFactory;

        $sections = [];
        $initialValues = $this->getInitialValuesForFactory($factory, $values);
        foreach ($this->definition['sections'] as $section) {
            $riaFields = [];
            foreach ($section['fields'] as $field) {
                $riaFields[] = $factory->prepareFieldForRia(
                    $field,
                    new FormOptionContext($this, $field, $values, $context),
                );
            }

            $riaSection = $section;
            $riaSection['fields'] = $riaFields;
            $sections[] = $riaSection;
        }

        $riaDefinition = $this->definition;
        $riaDefinition['sections'] = $sections;
        $riaDefinition['initialValues'] = $initialValues;

        FormJson::assertValue($riaDefinition, 'form');

        return $riaDefinition;
    }

    public function jsonSerialize(): array
    {
        return $this->getDefForRia();
    }

    /**
     * @param array<string,mixed> $values
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function getValuesSchema(array $values, array $context, bool $includeNonSubmittable): array
    {
        if ($this->definition['id'] === '') {
            throw new TechException('A form id must be defined before it is validated.');
        }

        $factory = $this->formFactory;
        $defaults = $this->getDefaultValuesForFactory($factory);
        $properties = [];
        $required = [];
        $references = [];

        foreach ($this->definition['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $name = $field['name'] ?? null;
                $typeId = $field['type'] ?? null;
                if (!is_string($name) || !is_string($typeId)) {
                    continue;
                }

                $fieldType = $factory->getType($typeId);
                if (
                    !$fieldType->hasValue
                    || (!$includeNonSubmittable && (!$fieldType->isSubmittable || ($field['submittable'] ?? true) === false))
                ) {
                    continue;
                }

                $isRequired = $field['required'] ?? false;
                if (!is_bool($isRequired)) {
                    throw new TechException("The required property of form field '$name' must be a boolean.");
                }

                $riaField = $factory->prepareFieldForRia(
                    $field,
                    new FormOptionContext($this, $field, $values, $context),
                );
                $fieldSchema = $factory->getValueSchemaForField($field) ?? [];

                if ($fieldType->acceptsOptions) {
                    $choiceSchema = $this->getChoiceSubmissionSchema(
                        $name,
                        $riaField,
                        $isRequired,
                        $defaults[$name] ?? null,
                    );
                    $fieldSchema = $fieldSchema === []
                        ? $choiceSchema
                        : ['allOf' => [$fieldSchema, $choiceSchema]];
                }

                $validation = $field['validation'] ?? null;
                if ($validation !== null) {
                    if (!is_array($validation)) {
                        throw new TechException("The validation property of form field '$name' must be an array.");
                    }

                    if ($validation !== []) {
                        $fieldSchema = $fieldSchema === []
                            ? $validation
                            : ['allOf' => [$fieldSchema, $validation]];
                    }
                }

                $properties[$name] = $fieldSchema;
                if ($isRequired) {
                    $required[] = $name;
                }

                $this->addSubmissionReferences($references, $field, $name);
            }
        }

        $schema = [
            'type' => 'object',
            'properties' => $properties,
            'additionalProperties' => false,
        ];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        $document = $references === []
            ? $schema
            : ['schema' => $schema, 'schemas_ref' => $references];

        FormJson::assertValue($document, $includeNonSubmittable ? 'persistenceSchema' : 'submissionSchema');

        return $document;
    }

    private function findSectionIndex(string $sectionId): int
    {
        foreach ($this->definition['sections'] as $index => $section) {
            if ($section['id'] === $sectionId) {
                return $index;
            }
        }

        throw new TechException("Unknown form section '$sectionId'.");
    }

    private function assertFieldNameIsAvailable(string $name): void
    {
        foreach ($this->definition['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                if (($field['name'] ?? null) === $name) {
                    throw new TechException("Form field '$name' is already defined.");
                }
            }
        }
    }

    /** @return array<string,mixed> */
    private function getDefaultValuesForFactory(FormFactory $factory): array
    {
        $defaults = [];
        foreach ($this->definition['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $name = $field['name'] ?? null;
                $typeId = $field['type'] ?? null;
                if (!is_string($name) || !is_string($typeId)) {
                    continue;
                }

                $fieldType = $factory->getType($typeId);
                if (!$fieldType->hasValue || !$fieldType->hasDefaultValue) {
                    continue;
                }

                $defaults[$name] = array_key_exists('default', $field)
                    ? $field['default']
                    : $factory->getImplicitDefaultForField($field);
            }
        }

        FormJson::assertValue($defaults, 'defaultValues');

        return $defaults;
    }

    /**
     * @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    private function getInitialValuesForFactory(FormFactory $factory, array $values): array
    {
        $defaults = $this->getDefaultValuesForFactory($factory);
        $initialValues = [];

        foreach ($this->definition['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $name = $field['name'] ?? null;
                $typeId = $field['type'] ?? null;
                if (!is_string($name) || !is_string($typeId) || !$factory->getType($typeId)->hasValue) {
                    continue;
                }

                if (array_key_exists($name, $values)) {
                    $initialValues[$name] = $values[$name];
                } elseif (array_key_exists($name, $defaults)) {
                    $initialValues[$name] = $defaults[$name];
                }
            }
        }

        return $initialValues;
    }

    /**
     * @param array<string,mixed> $riaField
     * @return array<string,mixed>
     */
    private function getChoiceSubmissionSchema(
        string $name,
        array $riaField,
        bool $isRequired,
        mixed $defaultValue,
    ): array {
        $options = $riaField['options'] ?? [];
        if (!is_array($options)) {
            throw new TechException("The options of form field '$name' must be an array.");
        }

        $allowedValues = [];
        foreach ($options as $option) {
            if (!is_array($option) || !array_key_exists('value', $option)) {
                throw new TechException("The options of form field '$name' are invalid.");
            }

            $allowedValues[] = $option['value'];
        }

        $isMultiple = ($riaField['multiple'] ?? false) === true;
        $isNullable = !$isRequired && $defaultValue === null;

        if ($allowedValues === []) {
            if ($isNullable) {
                return ['type' => 'null'];
            }

            throw new TechException("The required choice field '$name' has no available options.");
        }

        if (!$isMultiple) {
            if ($isNullable) {
                $allowedValues[] = null;
            }

            return ['enum' => $allowedValues];
        }

        $multipleSchema = [
            'type' => 'array',
            'items' => ['enum' => $allowedValues],
        ];

        return $isNullable
            ? ['anyOf' => [$multipleSchema, ['type' => 'null']]]
            : $multipleSchema;
    }

    /**
     * @param array<string,array<string,mixed>> $references
     * @param array<string,mixed> $field
     */
    private function addSubmissionReferences(array &$references, array $field, string $fieldName): void
    {
        $fieldReferences = $field['schemas_ref'] ?? [];
        if (!is_array($fieldReferences)) {
            throw new TechException("The schemas_ref property of form field '$fieldName' must be an array.");
        }

        foreach ($fieldReferences as $referenceName => $referenceSchema) {
            if (!is_string($referenceName) || !is_array($referenceSchema)) {
                throw new TechException("The schemas_ref property of form field '$fieldName' is invalid.");
            }

            if (isset($references[$referenceName]) && $references[$referenceName] !== $referenceSchema) {
                throw new TechException("The '$referenceName' JSON schema reference is declared more than once.");
            }

            $references[$referenceName] = $referenceSchema;
        }
    }

    private function assertIdentifier(string $identifier, string $label): void
    {
        if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/D', $identifier) !== 1) {
            throw new TechException("Invalid $label '$identifier'.");
        }
    }

    /** @param array<string,mixed> $options @param list<string> $reservedKeys */
    private function assertNoReservedKeys(array $options, array $reservedKeys, string $itemType): void
    {
        foreach ($reservedKeys as $reservedKey) {
            if (array_key_exists($reservedKey, $options)) {
                throw new TechException("The '$reservedKey' property of a form $itemType must be passed as an argument, not inside options.");
            }
        }
    }
}
