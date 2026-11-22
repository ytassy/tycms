<?php

return [
    'type' => 'object',
    'properties' => [
        'key' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 150],
        'locale' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 35],
    ],
    'required' => ['key', 'locale'],
    'additionalProperties' => false,
];
