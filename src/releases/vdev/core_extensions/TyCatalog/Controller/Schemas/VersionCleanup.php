<?php

declare(strict_types=1);

return [
    'type' => 'object',
    'properties' => [
        'catalogId' => ['type' => 'integer', 'minimum' => 1],
    ],
    'required' => ['catalogId'],
    'additionalProperties' => false,
];
