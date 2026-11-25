<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Security\Checks;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\ISecurityCheck;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\SecurityFinding;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/**
 * Jeton du worker de planification.
 *
 * La route `/cron` déclenche les scénarios planifiés ; sans jeton, elle reste
 * ouverte à qui connaît l'URL. Le contrôle lit la valeur configurée, sans la
 * journaliser ni la renvoyer.
 */
final class CronTokenCheck extends BaseHelper implements ISecurityCheck
{
    public function group(): string
    {
        return 'Planification';
    }

    public function check(): array
    {
        $token = (string) ($this->app->getConf()->cron_token ?? '');

        if (trim($token) !== '') {
            return [new SecurityFinding(
                'Jeton du planificateur',
                SecurityFinding::OK,
                'La route de planification est protégée par un jeton.',
            )];
        }

        return [new SecurityFinding(
            'Jeton du planificateur absent',
            SecurityFinding::CRITICAL,
            'La route `/cron` n’est protégée par aucun jeton : toute personne connaissant '
            . 'son adresse peut déclencher les scénarios planifiés.',
            'Définissez `cron_token` avec une valeur aléatoire longue.',
            'configuration.php — cron_token',
        )];
    }
}
