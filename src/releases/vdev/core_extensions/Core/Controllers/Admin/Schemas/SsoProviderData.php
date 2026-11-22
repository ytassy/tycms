<?php

return [
    'type' => 'object',
    'properties' => [
        'space_id' => ['type' => 'integer', 'minimum' => 1],
        'protocol' => ['type' => 'string', 'enum' => ['oidc', 'oauth2']],
        'name' => ['type' => 'string', 'maxLength' => 150],
        'enabled' => ['type' => 'boolean'],
        'login_enabled' => ['type' => 'boolean'],
        'integration_enabled' => ['type' => 'boolean'],
        'auto_link_account' => ['type' => 'boolean'],
        'login_button_text' => ['type' => 'string', 'maxLength' => 150],
        'register_button_text' => ['type' => 'string', 'maxLength' => 150],
        'config_json' => ['type' => 'string', 'maxBytes' => 32768],
        'mapping_json' => ['type' => 'string', 'maxBytes' => 32768],
        'secret_action' => ['type' => 'string', 'enum' => ['keep', 'replace', 'remove']],
        'client_secret' => ['type' => 'string', 'maxBytes' => 8192],
    ],
    'additionalProperties' => false,
];
