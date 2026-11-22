<?php

return [
    'type' => 'object',
    'properties' => [
        'key' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 150],
        'locale' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 35],
        'subject' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
        'bodyHtml' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 131072],
    ],
    'required' => ['key', 'locale', 'subject', 'bodyHtml'],
    'additionalProperties' => false,
];
