<?php

return [
    'type' => 'object',
    'properties' => [
        'parent_id' => ['type' => 'integer', 'minimum' => 0],
        'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
        'description' => ['type' => 'string', 'maxLength' => 5000],
        'role_ids' => [
            'type' => 'array', 'maxItems' => 250, 'uniqueItems' => true,
            'items' => ['type' => 'integer', 'minimum' => 1],
        ],
    ],
    'required' => ['parent_id', 'name', 'description'],
    'additionalProperties' => false,
];
