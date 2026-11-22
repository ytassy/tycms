<?php

declare(strict_types=1);

namespace YTassy\Tycms\Lib\Forms;

use YTassy\Tycms\Exceptions\TechException;

/** Metadata of a field type shared by PHP and the Vue field registry. */
final class FormFieldType
{
    private readonly mixed $implicitDefault;

    /** @var array<string,mixed>|\Closure(array<string,mixed>):array<string,mixed>|null */
    private readonly array|\Closure|null $valueSchema;

    /** @var array<int|string,mixed>|null */
    private readonly ?array $defaultOptions;

    private readonly ?FormOptionsProvider $defaultOptionsProvider;

    public function __construct(
        public readonly string $id,
        public readonly string $vueComponent,
        public readonly bool $acceptsOptions = false,
        public readonly bool $hasValue = true,
        public readonly bool $isSubmittable = true,
        public readonly bool $hasDefaultValue = true,
        mixed $implicitDefault = null,
        array|\Closure|null $valueSchema = null,
        ?array $defaultOptions = null,
        ?FormOptionsProvider $defaultOptionsProvider = null,
    ) {
        $this->implicitDefault = $implicitDefault;
        $this->valueSchema = $valueSchema;

        if ($defaultOptions !== null && $defaultOptionsProvider !== null) {
            throw new TechException("Form field type '$this->id' cannot define both defaultOptions and defaultOptionsProvider.");
        }

        if (($defaultOptions !== null || $defaultOptionsProvider !== null) && !$this->acceptsOptions) {
            throw new TechException("Form field type '$this->id' must accept options to define default options.");
        }

        $this->defaultOptions = $defaultOptions === null
            ? null
            : FormOption::normaliseList($defaultOptions);
        $this->defaultOptionsProvider = $defaultOptionsProvider;

        if (preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/D', $this->id) !== 1) {
            throw new TechException("Invalid form field type id '$this->id'.");
        }

        if (preg_match('/^[A-Za-z][A-Za-z0-9_\/-]*$/D', $this->vueComponent) !== 1) {
            throw new TechException("Invalid Vue component id '$this->vueComponent'.");
        }
    }

    /** @param array<string,mixed> $field */
    public function getImplicitDefault(array $field): mixed
    {
        if ($this->implicitDefault instanceof \Closure) {
            return ($this->implicitDefault)($field);
        }

        return $this->implicitDefault;
    }

    /**
     * Returns the trusted ObjectValidator schema for one value of this type.
     * A custom type without a schema remains valid but is still constrained to
     * the field names declared by its Form.
     *
     * @param array<string,mixed> $field
     * @return array<string,mixed>|null
     */
    public function getValueSchema(array $field): ?array
    {
        $schema = $this->valueSchema instanceof \Closure
            ? ($this->valueSchema)($field)
            : $this->valueSchema;

        if ($schema === null) {
            return null;
        }

        if (!is_array($schema)) {
            throw new TechException("The value schema of form field type '$this->id' must be an array.");
        }

        return $schema;
    }

    /**
     * Returns the options contributed by this type when the field does not
     * declare options or an optionsProvider of its own.
     *
     * @return array<int|string,mixed>|null
     */
    public function getDefaultOptions(FormOptionContext $context): ?array
    {
        if ($this->defaultOptionsProvider !== null) {
            return $this->defaultOptionsProvider->resolve($context);
        }

        return $this->defaultOptions;
    }
}
