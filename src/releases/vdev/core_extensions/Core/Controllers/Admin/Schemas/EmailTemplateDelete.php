<?php

return [
    'type' => 'object',
    'properties' => [
        'key' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 150],
    ],
    'required' => ['key'],
    'additionalProperties' => false,
];
