<?php

return [
    'type' => 'object',
    'properties' => [
        'key' => ['type' => 'string', 'minLength' => 4, 'maxLength' => 150],
        'changes' => [
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 20,
            'items' => [
                'type' => 'object',
                'properties' => [
                    'locale' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 35],
                    'action' => ['type' => 'string', 'enum' => ['save', 'delete']],
                    'value' => ['type' => 'string', 'maxLength' => 20000],
                ],
                'required' => ['locale', 'action'],
                'additionalProperties' => false,
            ],
        ],
    ],
    'required' => ['key', 'changes'],
    'additionalProperties' => false,
];
