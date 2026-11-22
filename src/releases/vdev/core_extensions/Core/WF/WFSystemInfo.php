<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits\TraitCoreSharedHelpers;
use YTassy\Tycms\WF\BaseWF;

class WFSystemInfo extends BaseWF
{
    use TraitCoreSharedHelpers;

    public function getInfos(): array
    {
        if (!$this->coreAclHelper->isRoot()) {
            throw new AccessDeniedException();
        }

        $extensions = [];
        foreach (get_loaded_extensions() as $extension) {
            $extensions[$extension] = phpversion($extension) ?: null;
        }
        ksort($extensions);

        $zendExtensions = [];
        foreach (get_loaded_extensions(true) as $extension) {
            $zendExtensions[$extension] = phpversion($extension) ?: null;
        }
        ksort($zendExtensions);

        return [
            'php' => [
                'version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'zend_version' => zend_version(),
                'os' => PHP_OS_FAMILY,
                'architecture' => PHP_INT_SIZE * 8,
                'thread_safe' => PHP_ZTS === 1,
                'debug' => PHP_DEBUG === 1,
                'loaded_ini_file' => php_ini_loaded_file() ?: null,
                'scanned_ini_files' => php_ini_scanned_files() ?: null,
                'extensions' => $extensions,
                'zend_extensions' => $zendExtensions,
                'settings' => ini_get_all(),
            ],
            'routes' => $this->router->getAllRoutes(),
        ];
    }
}
