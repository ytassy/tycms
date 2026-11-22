<?php

return [
    'type' => 'object',
    'properties' => [
        'home_item_id' => ['type' => 'integer', 'minimum' => 0],
        'user_space_item_id' => ['type' => 'integer', 'minimum' => 0],
        'login_item_id' => ['type' => 'integer', 'minimum' => 0],
        'register_item_id' => ['type' => 'integer', 'minimum' => 0],
        'languages' => [
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 20,
            'items' => [
                'type' => 'object',
                'properties' => [
                    'locale' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 35],
                    'home_item_id' => ['type' => 'integer', 'minimum' => 0],
                    'user_space_item_id' => ['type' => 'integer', 'minimum' => 0],
                ],
                'required' => ['locale', 'home_item_id', 'user_space_item_id'],
                'additionalProperties' => false,
            ],
        ],
    ],
    'required' => ['home_item_id', 'user_space_item_id', 'languages'],
    'additionalProperties' => false,
];
