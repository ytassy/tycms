<?php

declare(strict_types=1);

return [
    'type' => 'object',
    'properties' => [
        'catalogId' => ['type' => 'integer', 'minimum' => 1],
        'tokenId' => ['type' => 'integer', 'minimum' => 1],
    ],
    'required' => ['catalogId', 'tokenId'],
    'additionalProperties' => false,
];
