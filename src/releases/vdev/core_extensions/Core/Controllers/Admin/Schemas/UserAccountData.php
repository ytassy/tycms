<?php

return [
    'type' => 'object',
    'properties' => [
        'first_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
        'last_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
        'email' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 100],
        'timezone' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
        'blocked' => ['type' => 'boolean'],
        'send_email' => ['type' => 'boolean'],
    ],
    'required' => ['first_name', 'last_name', 'email', 'timezone', 'blocked', 'send_email'],
    'additionalProperties' => false,
];
