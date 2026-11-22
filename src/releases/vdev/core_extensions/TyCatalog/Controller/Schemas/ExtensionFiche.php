<?php

declare(strict_types=1);

return [
    'type' => 'object',
    'properties' => [
        'catalogId' => ['type' => 'integer', 'minimum' => 1],
        'identifier' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 80, 'pattern' => '^[a-z0-9_]+$'],
        'displayName' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
        'description' => ['type' => 'string', 'maxLength' => 2000],
        'imageUrl' => ['type' => ['string', 'null'], 'maxLength' => 2000],
    ],
    'required' => ['catalogId', 'identifier', 'displayName', 'description'],
    'additionalProperties' => false,
];
