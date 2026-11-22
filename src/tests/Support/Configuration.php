<?php

declare(strict_types=1);

require_once __DIR__ . '/../../releases/vdev/configuration.base.php';

// Les tests héritent des valeurs du framework, sans charger les secrets du site.
class Configuration extends ConfigurationBase
{
    // Une capture du framework dans un test ne doit jamais alimenter les journaux du site.
    public string $log_path = APP_ROOT . '/tests/.runtime/unit-logs';
}
