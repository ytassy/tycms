<?php

$space = require __DIR__ . '/SpaceData.php';
$fields = [
    'mail_online', 'mailer', 'mail_from', 'mail_from_name', 'mail_smtp_auth',
    'mail_smtp_user', 'mail_smtp_pass', 'mail_smtp_host', 'mail_smtp_secure',
    'mail_smtp_port',
];

return [
    'type' => 'object',
    'properties' => array_intersect_key($space['properties'], array_flip($fields)),
    'required' => $fields,
    'additionalProperties' => false,
];
