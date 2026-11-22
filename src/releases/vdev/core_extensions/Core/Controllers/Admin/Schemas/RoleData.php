<?php

return [
    'type' => 'object',
    'properties' => [
        'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
        'description' => ['type' => 'string', 'maxLength' => 5000],
        'permissions' => [
            'type' => 'array',
            'maxItems' => 250,
            'uniqueItems' => true,
            'items' => ['type' => 'string', 'maxLength' => 101],
        ],
    ],
    'required' => ['name', 'description', 'permissions'],
    'additionalProperties' => false,
];
