<?php

return [
    'type' => 'object',
    'properties' => [
        'key' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 150],
        'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
        'description' => ['type' => 'string', 'maxLength' => 500],
        'renderMode' => ['type' => 'string', 'enum' => ['default', 'blank']],
        'variables' => [
            'type' => 'array',
            'maxItems' => 20,
            'items' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
                    'example' => ['type' => 'string', 'maxLength' => 200],
                ],
                'required' => ['name', 'example'],
                'additionalProperties' => false,
            ],
        ],
    ],
    'required' => ['key', 'label', 'description', 'renderMode', 'variables'],
    'additionalProperties' => false,
];
