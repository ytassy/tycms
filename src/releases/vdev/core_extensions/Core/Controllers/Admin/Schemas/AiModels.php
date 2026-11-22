<?php

$selection = [
    'type' => 'object',
    'properties' => [
        'provider_id' => ['type' => 'integer', 'minimum' => 1],
        'model' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
    ],
    'required' => ['provider_id', 'model'],
    'additionalProperties' => false,
];

return [
    'type' => 'object',
    'properties' => [
        'decision' => $selection,
        'proofreading' => $selection,
        'design' => $selection,
        'image_generation' => $selection,
    ],
    'additionalProperties' => false,
];
