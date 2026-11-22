<?php

declare(strict_types=1);

return [
    'type' => 'object',
    'properties' => [
        'catalogId' => ['type' => 'integer', 'minimum' => 1],
        'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
        'abilities' => [
            'type' => 'array',
            'minItems' => 1,
            'maxItems' => 2,
            'items' => ['type' => 'string', 'enum' => ['deposit', 'publish']],
        ],
        // 0 signifie « sans expiration ».
        'days' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 3650],
    ],
    'required' => ['catalogId', 'label', 'abilities'],
    'additionalProperties' => false,
];
