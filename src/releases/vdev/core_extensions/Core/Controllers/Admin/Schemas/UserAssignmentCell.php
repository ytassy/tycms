<?php

return [
    'type' => 'object',
    'properties' => [
        'user_id' => ['type' => 'integer', 'minimum' => 1],
        'space_id' => ['type' => 'integer', 'minimum' => 1],
        'kind' => ['type' => 'string', 'enum' => ['role', 'group']],
        'item_id' => ['type' => 'integer', 'minimum' => 1],
        'before' => ['type' => 'boolean'],
        'assigned' => ['type' => 'boolean'],
    ],
    'required' => ['user_id', 'space_id', 'kind', 'item_id', 'before', 'assigned'],
    'additionalProperties' => false,
];
