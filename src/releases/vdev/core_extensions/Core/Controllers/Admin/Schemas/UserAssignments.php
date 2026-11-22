<?php

$ids = [
    'type' => 'array', 'maxItems' => 1000, 'uniqueItems' => true,
    'items' => ['type' => 'integer', 'minimum' => 1],
];

return [
    'type' => 'object',
    'properties' => ['before' => $ids, 'after' => $ids],
    'required' => ['before', 'after'],
    'additionalProperties' => false,
];
