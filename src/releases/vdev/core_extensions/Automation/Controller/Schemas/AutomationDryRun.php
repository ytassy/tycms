<?php

return [
    'type' => 'object',
    'properties' => [
        'scenario_id' => ['type' => 'integer', 'minimum' => 0],
        'space_id' => ['type' => 'integer', 'minimum' => 0],
        'source' => ['type' => 'string', 'maxLength' => 65536],
        'input' => ['type' => 'object'],
    ],
    'required' => ['scenario_id', 'space_id', 'source', 'input'],
    'additionalProperties' => false,
];
