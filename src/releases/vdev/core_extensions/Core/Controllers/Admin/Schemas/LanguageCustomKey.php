<?php

return [
    'type' => 'object',
    'properties' => [
        'key' => ['type' => 'string', 'minLength' => 4, 'maxLength' => 150],
        'sourceValue' => ['type' => 'string', 'maxLength' => 5000],
    ],
    'required' => ['key', 'sourceValue'],
    'additionalProperties' => false,
];
