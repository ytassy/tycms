<?php

return [
    'type' => 'object',
    'properties' => [
        'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
        'alias' => ['type' => 'string', 'maxLength' => 100],
        'description' => ['type' => 'string', 'maxLength' => 1_000],
    ],
    'required' => ['name'],
    'additionalProperties' => false,
];
