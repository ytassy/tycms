<?php

declare(strict_types=1);

return [
    'type' => 'object',
    'properties' => [
        'catalogId' => ['type' => 'integer', 'minimum' => 1],
        'archiveUrl' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
        'notesUrl' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 2000],
        // Enveloppe JSON originale du build, jamais reconstruite par le client.
        'envelope' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 131072],
        'displayName' => ['type' => 'string', 'maxLength' => 150],
        'description' => ['type' => 'string', 'maxLength' => 2000],
    ],
    'required' => ['catalogId', 'archiveUrl', 'notesUrl', 'envelope'],
    'additionalProperties' => false,
];
