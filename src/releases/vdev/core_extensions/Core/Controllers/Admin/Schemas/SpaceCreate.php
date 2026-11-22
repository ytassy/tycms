<?php

$schema = require __DIR__ . '/SpaceData.php';
$schema['required'] = ['parent_id', 'template', 'hosts', 'title'];

return $schema;
