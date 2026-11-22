<?php

declare(strict_types=1);

return [
    'type' => 'object',
    'properties' => [
        'catalogId' => ['type' => 'integer', 'minimum' => 1],
        'versionId' => ['type' => 'integer', 'minimum' => 1],
    ],
    'required' => ['catalogId', 'versionId'],
    'additionalProperties' => false,
];
