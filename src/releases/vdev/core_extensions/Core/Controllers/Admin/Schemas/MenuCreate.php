<?php

return [
    'type' => 'object',
    'properties' => [
        'space_id' => ['type' => 'integer', 'minimum' => 1],
        'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
        'alias' => ['type' => 'string', 'maxLength' => 100],
        'description' => ['type' => 'string', 'maxLength' => 1_000],
    ],
    'required' => ['space_id', 'name'],
    'additionalProperties' => false,
];
