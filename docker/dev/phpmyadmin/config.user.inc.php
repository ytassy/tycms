<?php

// Laisser l'authentification habituelle lorsque l'accès automatique est désactivé.
if (filter_var(getenv('DB_ADMIN_AUTO_LOGIN'), FILTER_VALIDATE_BOOLEAN)) {
    $cfg['Servers'][$i]['auth_type'] = 'config';
    $cfg['Servers'][$i]['user'] = 'root';
    $cfg['Servers'][$i]['password'] = getenv('DB_ROOT_PASSWORD');
}
