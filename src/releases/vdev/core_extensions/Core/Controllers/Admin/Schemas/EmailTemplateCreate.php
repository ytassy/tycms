<?php

return [
    'type' => 'object',
    'properties' => [
        'label' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
        'description' => ['type' => 'string', 'maxLength' => 500],
        'renderMode' => ['type' => 'string', 'enum' => ['default', 'blank']],
        'locale' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 35],
        'subject' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
        'bodyHtml' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 131072],
        'variables' => [
            'type' => 'array',
            'maxItems' => 20,
            'items' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 50],
                    'example' => ['type' => 'string', 'maxLength' => 200],
                ],
                'required' => ['name', 'example'],
                'additionalProperties' => false,
            ],
        ],
    ],
    'required' => ['label', 'description', 'renderMode', 'locale', 'subject', 'bodyHtml', 'variables'],
    'additionalProperties' => false,
];
