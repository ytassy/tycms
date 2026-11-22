<?php

return [
    'type' => 'object',
    'properties' => ['password' => ['type' => 'string', 'minLength' => 12, 'maxLength' => 72]],
    'required' => ['password'],
    'additionalProperties' => false,
];
