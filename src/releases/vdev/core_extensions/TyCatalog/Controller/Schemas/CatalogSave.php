<?php

declare(strict_types=1);

return [
    'type' => 'object',
    'properties' => [
        // Absent ou null pour une création ; entier positif pour une modification.
        'id' => ['type' => ['integer', 'null'], 'minimum' => 1],
        // Espace cible ; ROOT uniquement, ignorée pour les autres comptes.
        'spaceId' => ['type' => ['integer', 'null'], 'minimum' => 1],
        'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
        'slug' => [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 80,
            'pattern' => '^[a-z0-9]+(?:-[a-z0-9]+)*$',
        ],
        'description' => ['type' => 'string', 'maxLength' => 5000],
        'enabled' => ['type' => 'boolean'],
        // Rétention par extension ; 1 à 100 versions et ZIP.
        'keep_versions' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
        'keep_archives' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
        // Champs ROOT : ignorés par le WF pour les autres comptes.
        'key_id' => ['type' => ['string', 'null'], 'maxLength' => 64],
        'public_key' => ['type' => ['string', 'null'], 'maxLength' => 128],
        'links' => [
            'type' => 'array',
            'maxItems' => 50,
            'items' => [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => ['integer', 'null'], 'minimum' => 1],
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
                    'target_url' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 500],
                    'description' => ['type' => 'string', 'maxLength' => 2000],
                    'position' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9999],
                ],
                'required' => ['name', 'target_url', 'description', 'position'],
                'additionalProperties' => false,
            ],
        ],
    ],
    'required' => ['name', 'slug', 'description', 'enabled', 'keep_versions', 'keep_archives', 'links'],
    'additionalProperties' => false,
];
