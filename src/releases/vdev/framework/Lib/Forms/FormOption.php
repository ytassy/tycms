<?php

declare(strict_types=1);

namespace YTassy\Tycms\Lib\Forms;

use YTassy\Tycms\Exceptions\TechException;

/** Normalises static, callback and SQL choices to the Vue option contract. */
final class FormOption
{
    /**
     * Supports both ['draft' => 'Brouillon'] and
     * [['value' => 'draft', 'label' => 'Brouillon']].
     *
     * @param array<int|string,mixed> $options
     * @return list<array{value:string|int|float|bool|null,label:string,disabled?:bool,data?:array<string,mixed>}>
     */
    public static function normaliseList(array $options): array
    {
        $normalised = [];
        foreach ($options as $key => $option) {
            if (is_object($option)) {
                $option = get_object_vars($option);
            }

            if (is_array($option)) {
                if (!array_key_exists('value', $option)) {
                    throw new TechException("Form option at index '$key' must define a value.");
                }

                $value = $option['value'];
                $label = $option['label'] ?? $option['text'] ?? null;
                if (!is_string($label)) {
                    throw new TechException("Form option at index '$key' must define a string label or text.");
                }

                $item = ['value' => self::normaliseValue($value), 'label' => $label];
                if (array_key_exists('disabled', $option)) {
                    if (!is_bool($option['disabled'])) {
                        throw new TechException("The disabled value of form option '$key' must be a boolean.");
                    }
                    $item['disabled'] = $option['disabled'];
                }
                if (array_key_exists('data', $option)) {
                    if (!is_array($option['data'])) {
                        throw new TechException("The data of form option '$key' must be an array of scalar metadata.");
                    }

                    // Les composants spécialisés reçoivent des métadonnées JSON simples, jamais du code.
                    foreach ($option['data'] as $name => $metadata) {
                        if (!is_string($name) || (is_float($metadata) && !is_finite($metadata))) {
                            throw new TechException("Invalid metadata of form option '$key'.");
                        }
                        self::normaliseValue($metadata);
                    }
                    $item['data'] = $option['data'];
                }
                $normalised[] = $item;
                continue;
            }

            if (is_int($key) || is_string($key)) {
                if (!is_string($option)) {
                    throw new TechException("The label of form option '$key' must be a string.");
                }
                $normalised[] = ['value' => self::normaliseValue($key), 'label' => $option];
                continue;
            }

            throw new TechException("Invalid form option at index '$key'.");
        }

        return $normalised;
    }

    private static function normaliseValue(mixed $value): string|int|float|bool|null
    {
        if (!is_string($value) && !is_int($value) && !is_float($value) && !is_bool($value) && $value !== null) {
            throw new TechException('A form option value must be a scalar or null.');
        }

        return $value;
    }
}
