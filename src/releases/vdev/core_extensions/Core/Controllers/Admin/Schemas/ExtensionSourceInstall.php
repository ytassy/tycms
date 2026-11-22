<?php

declare(strict_types=1);

return [
    'type' => 'object',
    'properties' => [
        'sourceId' => ['type' => 'integer', 'minimum' => 1],
        'identifier' => [
            'type' => 'string',
            'maxLength' => 64,
            'pattern' => '^(?:plg|mod|tpl)_[a-z][a-z0-9_]*$',
        ],
        'version' => [
            'type' => 'string',
            'maxLength' => 48,
            'pattern' => '^(?:0|[1-9][0-9]*)\\.(?:0|[1-9][0-9]*)\\.(?:0|[1-9][0-9]*)$',
        ],
    ],
    'required' => ['sourceId', 'identifier', 'version'],
    'additionalProperties' => false,
];
