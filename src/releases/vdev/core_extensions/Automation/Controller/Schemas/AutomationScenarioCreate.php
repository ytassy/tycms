<?php

return [
    'type' => 'object',
    'properties' => [
        'space_id' => ['type' => 'integer', 'minimum' => 1],
        'key' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
        'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 150],
        'description' => ['type' => 'string', 'maxLength' => 2000],
        'principal_mode' => ['type' => 'string', 'enum' => ['trigger', 'author']],
        'trigger_id' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 100],
        'trigger_config' => ['type' => 'object', 'maxProperties' => 10, 'additionalProperties' => true],
        'trigger_enabled' => ['type' => 'boolean'],
        'source' => ['type' => 'string', 'maxLength' => 65536],
    ],
    'required' => [
        'space_id',
        'key',
        'name',
        'description',
        'principal_mode',
        'trigger_id',
        'source',
    ],
    'additionalProperties' => false,
];
