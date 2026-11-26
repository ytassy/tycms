<?php

declare(strict_types=1);

return [
    'type' => 'object',
    'properties' => [
        'id' => ['type' => ['integer', 'null'], 'minimum' => 1],
        'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
        'baseUrl' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
        'keyId' => [
            'type' => 'string',
            'minLength' => 11,
            'maxLength' => 64,
            'pattern' => '^extensions-[a-zA-Z0-9_-]+$',
        ],
        'publicKey' => ['type' => 'string', 'minLength' => 40, 'maxLength' => 128],
        // Valeur absente/null = conserver la licence existante.
        'license' => ['type' => ['string', 'null'], 'maxLength' => 1000],
        'clearLicense' => ['type' => 'boolean'],
        'enabled' => ['type' => 'boolean'],
        // Canal demandé à ce catalogue ; absent = conserver la valeur enregistrée.
        'channel' => ['type' => 'string', 'enum' => ['release', 'beta']],
    ],
    'required' => ['name', 'baseUrl', 'keyId', 'publicKey'],
    'additionalProperties' => false,
];
