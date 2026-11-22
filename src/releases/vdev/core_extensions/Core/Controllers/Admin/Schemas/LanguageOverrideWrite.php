<?php

return [
    'type' => 'object',
    'properties' => [
        'locale' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 35],
        'key' => ['type' => 'string', 'minLength' => 4, 'maxLength' => 150],
        'value' => ['type' => 'string', 'maxLength' => 20000],
    ],
    'required' => ['locale', 'key', 'value'],
    'additionalProperties' => false,
];
