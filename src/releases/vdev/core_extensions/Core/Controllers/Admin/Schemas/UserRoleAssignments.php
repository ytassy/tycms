<?php

$assignment = [
    'type' => 'object',
    'properties' => [
        'role_id' => ['type' => 'integer', 'minimum' => 1],
        'space_id' => ['type' => 'integer', 'minimum' => 1],
    ],
    'required' => ['role_id', 'space_id'],
    'additionalProperties' => false,
];

$assignments = ['type' => 'array', 'maxItems' => 1000, 'items' => $assignment];

return [
    'type' => 'object',
    'properties' => ['before' => $assignments, 'after' => $assignments],
    'required' => ['before', 'after'],
    'additionalProperties' => false,
];
