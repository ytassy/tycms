<?php

declare(strict_types=1);

return [
    'type' => 'object',
    'properties' => [
        'identifier' => [
            'type' => 'string',
            'maxLength' => 64,
            'pattern' => '^(?:plg|mod|tpl)_[a-z][a-z0-9_]*$',
        ],
        'operationId' => [
            'type' => 'string',
            'maxLength' => 32,
            'pattern' => '^[a-f0-9]{32}$',
        ],
    ],
    'required' => ['identifier', 'operationId'],
    'additionalProperties' => false,
];
