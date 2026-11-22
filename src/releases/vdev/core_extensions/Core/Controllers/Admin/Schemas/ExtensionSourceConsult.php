<?php

declare(strict_types=1);

return [
    'type' => 'object',
    'properties' => [
        'id' => ['type' => 'integer', 'minimum' => 1],
    ],
    'required' => ['id'],
    'additionalProperties' => false,
];
