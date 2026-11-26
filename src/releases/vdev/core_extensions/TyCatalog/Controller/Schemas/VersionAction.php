<?php

declare(strict_types=1);

return [
    'type' => 'object',
    'properties' => [
        'catalogId' => ['type' => 'integer', 'minimum' => 1],
        'versionId' => ['type' => 'integer', 'minimum' => 1],
        // Canal de diffusion à la publication ; ignoré par les autres actions.
        'channel' => ['type' => 'string', 'enum' => ['release', 'beta']],
    ],
    'required' => ['catalogId', 'versionId'],
    'additionalProperties' => false,
];
