<?php

$schema = require __DIR__ . '/RoleData.php';
$schema['properties']['space_id'] = ['type' => 'integer', 'minimum' => 1];
$schema['properties']['type'] = ['type' => 'integer', 'enum' => [0, 1, 2]];
$schema['required'][] = 'space_id';
$schema['required'][] = 'type';

return $schema;
