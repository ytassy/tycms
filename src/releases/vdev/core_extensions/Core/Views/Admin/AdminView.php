<?php

namespace YTassy\Tycms\CoreExts\Core\Views\Admin;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\RiaView;

class AdminView extends RiaView
{
    /** Variantes autorisées ; toute autre valeur retombe sur le rendu moderne. */
    private const ADMIN_UI_VALUES = ['classic', 'modern'];
    private const DEFAULT_ADMIN_UI = 'modern';

    public function __construct(BaseApplicationItem $app, bool $canEditExtensions)
    {
        parent::__construct(
            $app,
            'administrator/index',
            ['canEditExtensions' => $canEditExtensions], false, false
        );

        $this->adminUi = $this->resolveAdminUi();
    }

    /**
     * La variante est lue dans la configuration de la template du Space courant.
     * Une configuration absente ne doit pas empêcher l'accès à l'administration.
     */
    private function resolveAdminUi(): string
    {
        try {
            // Lire la configuration de la template réellement utilisée par le Space,
            // et non un identifiant de template codé en dur.
            $space = $this->coreSpaceHelper->getCurrentSpace();
            $params = $this->coreTemplateHelper->getTemplateConf($space->template, $space->id)->params;
        } catch (\Throwable $exception) {
            $this->logWarning(
                'Configuration de la template indisponible pour la variante admin.',
                [
                    'exception' => $exception::class,
                    'message' => $exception->getMessage(),
                ],
                'ADMIN_UI_CONFIG_FALLBACK',
            );
            return self::DEFAULT_ADMIN_UI;
        }

        $value = $params->theme_admin_ui ?? null;

        // L'attribut HTML ne reçoit qu'une valeur explicitement prévue.
        return is_string($value) && in_array($value, self::ADMIN_UI_VALUES, true)
            ? $value
            : self::DEFAULT_ADMIN_UI;
    }

}
