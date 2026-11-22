<?php

return [
    'type' => 'object',
    'properties' => [
        'name' => ['type' => 'string', 'maxLength' => 150],
        'type' => ['type' => 'string', 'enum' => ['openai', 'openai_compatible', 'openrouter', 'infomaniak', 'anthropic']],
        'base_url' => ['type' => 'string', 'maxLength' => 500],
        'enabled' => ['type' => 'boolean'],
        'config' => ['type' => 'object'],
        'api_key' => ['type' => 'string', 'maxBytes' => 8192],
    ],
    'additionalProperties' => false,
];
