<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Security\Checks;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\ISecurityCheck;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\SecurityFinding;

/**
 * Mode développement et affichage des erreurs.
 *
 * Le mode DEV et le débogage détaillé exposent des chemins, des requêtes et des
 * traces techniques : utiles au développement, dangereux dès que le site est
 * accessible. L'alerte est émise même en développement, pour rendre l'état
 * visible et permettre de vérifier le bilan d'analyse lui-même.
 */
final class DebugModeCheck extends BaseHelper implements ISecurityCheck
{
    public function group(): string
    {
        return 'Environnement';
    }

    public function check(): array
    {
        $configuration = $this->app->getConf();
        $dev = (bool) ($configuration->dev ?? false);
        $debug = (bool) ($configuration->debug ?? false);

        if (!$dev && !$debug) {
            return [new SecurityFinding(
                'Mode développement',
                SecurityFinding::OK,
                'Le mode développement et le débogage sont désactivés.',
            )];
        }

        $active = [];

        if ($dev) {
            $active[] = 'le mode développement (`dev`)';
        }

        if ($debug) {
            $active[] = 'le débogage (`debug`)';
        }

        return [new SecurityFinding(
            'Mode développement actif',
            SecurityFinding::DANGER,
            'Ce site tourne avec ' . implode(' et ', $active) . '. Des informations techniques '
            . 'peuvent être affichées aux visiteurs : traces, requêtes, chemins de fichiers.',
            'Désactivez `dev` et `debug` une fois le développement terminé.',
            'configuration.php — dev, debug',
        )];
    }
}
