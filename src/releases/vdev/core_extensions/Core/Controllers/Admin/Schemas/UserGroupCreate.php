<?php

$schema = require __DIR__ . '/UserGroupData.php';
$schema['properties']['space_id'] = ['type' => 'integer', 'minimum' => 1];
$schema['required'][] = 'space_id';

return $schema;
