<?php

$schema = require __DIR__ . '/UserAccountData.php';
$schema['properties']['space_id'] = ['type' => 'integer', 'minimum' => 1];
$schema['properties']['password'] = ['type' => 'string', 'minLength' => 12, 'maxLength' => 72];
$schema['properties']['send_welcome_email'] = ['type' => 'boolean'];
$schema['required'][] = 'space_id';
$schema['required'][] = 'password';

return $schema;
