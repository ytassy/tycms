<?php

return [
    'type' => 'array',
    'minItems' => 1,
    'maxItems' => 20,
    'items' => [
        'type' => 'object',
        'properties' => [
            'locale' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 35],
            'enabled' => ['type' => 'boolean'],
            'is_default' => ['type' => 'boolean'],
        ],
        'required' => ['locale', 'enabled', 'is_default'],
        'additionalProperties' => false,
    ],
];
