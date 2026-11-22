<?php

return [
    'type' => 'object',
    'properties' => [
        'parent_id' => [
            'type' => ['integer', 'null'],
            'minimum' => 1,
        ],
        'status' => [
            'type' => 'integer',
            'enum' => [1, 0, -1, -2],
        ],
        'extension' => [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 50,
        ],
        'type' => [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 50,
        ],
        'definitionKey' => [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 110,
        ],
        'params' => [
            'anyOf' => [
                [
                    'type' => 'object',
                    'additionalProperties' => true,
                ],
                [
                    'type' => 'array',
                ],
            ],
        ],
        'title' => [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 100,
        ],
        'alias' => [
            'type' => 'string',
            'maxLength' => 100,
        ],
        'languages' => [
            'type' => 'array',
            'maxItems' => 20,
            'items' => [
                'type' => 'object',
                'properties' => [
                    'locale' => ['type' => 'string', 'maxLength' => 35],
                    'enabled' => ['type' => 'boolean'],
                ],
                'required' => ['locale', 'enabled'],
                'additionalProperties' => false,
            ],
        ],
        'css' => [
            'type' => 'string',
            'maxLength' => 100,
        ],
        'icon' => [
            'type' => 'string',
            'maxLength' => 100,
        ],
        'display_in_menu' => [
            'type' => 'boolean',
        ],
        'browser_title' => [
            'type' => 'string',
            'maxLength' => 100,
        ],
        'meta_description' => [
            'type' => 'string',
            'maxLength' => 1_000,
        ],
        'meta_robots' => [
            'type' => 'string',
            'enum' => [
                'index, follow',
                'noindex, follow',
                'index, nofollow',
                'noindex, nofollow',
            ],
        ],
        'page_title' => [
            'type' => 'string',
            'maxLength' => 100,
        ],
        'access_role_id' => [
            'type' => ['integer', 'null'],
            'minimum' => 0,
        ],
    ],
    'additionalProperties' => false,
];
