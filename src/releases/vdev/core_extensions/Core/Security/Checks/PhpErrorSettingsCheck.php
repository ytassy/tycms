<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Security\Checks;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\ISecurityCheck;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\SecurityFinding;

/**
 * Réglages PHP des erreurs appliqués par le bootstrap.
 *
 * Afficher les erreurs dans la réponse expose les chemins, les requêtes et les
 * détails techniques aux visiteurs. Les masquer sans les journaliser rend en
 * revanche toute panne difficile à diagnostiquer. Le contrôle lit les valeurs
 * réellement appliquées depuis `configuration.php`, sans se fier au php.ini
 * supposé de l'hébergement.
 */
final class PhpErrorSettingsCheck extends BaseHelper implements ISecurityCheck
{
    /** Niveaux indispensables pour qu'une erreur ou un avertissement soit journalisé. */
    private const REQUIRED_LEVELS = E_ERROR | E_WARNING;

    public function group(): string
    {
        return 'Environnement';
    }

    public function check(): array
    {
        $configuration = $this->app->getConf();

        $displayErrors = (bool) ($configuration->display_errors ?? true);
        $logErrors = (bool) ($configuration->log_errors ?? false);
        $level = (int) ($configuration->error_reporting_level ?? 0);

        $findings = [
            $this->displayFinding($displayErrors),
            $this->traceabilityFinding($logErrors, $level),
        ];

        return $findings;
    }

    private function displayFinding(bool $displayErrors): SecurityFinding
    {
        if (!$displayErrors) {
            return new SecurityFinding(
                'Affichage des erreurs PHP',
                SecurityFinding::OK,
                'Les erreurs PHP ne sont pas affichées dans les réponses.',
            );
        }

        return new SecurityFinding(
            'Affichage des erreurs PHP',
            SecurityFinding::DANGER,
            'Les erreurs PHP sont affichées dans la réponse. Un visiteur peut voir des chemins '
            . 'de fichiers, des requêtes SQL ou d’autres détails techniques du serveur.',
            'Passez `display_errors` à false.',
            'configuration.php — display_errors',
        );
    }

    private function traceabilityFinding(bool $logErrors, int $level): SecurityFinding
    {
        $levelCoversErrors = ($level & self::REQUIRED_LEVELS) === self::REQUIRED_LEVELS;

        if ($logErrors && $levelCoversErrors) {
            return new SecurityFinding(
                'Journalisation des erreurs PHP',
                SecurityFinding::OK,
                'Les erreurs et avertissements PHP sont écrits dans le journal du serveur.',
            );
        }

        $problems = [];

        if (!$logErrors) {
            $problems[] = 'l’écriture dans le journal du serveur est désactivée (`log_errors`)';
        }

        if (!$levelCoversErrors) {
            $problems[] = 'le niveau configuré exclut les erreurs ou les avertissements (`error_reporting_level`)';
        }

        return new SecurityFinding(
            'Journalisation des erreurs PHP incomplète',
            SecurityFinding::INFO,
            'Une panne peut devenir difficile à diagnostiquer : ' . implode(' et ', $problems) . '.',
            'Activez `log_errors` et utilisez un niveau incluant `E_ERROR` et `E_WARNING`, '
            . 'par exemple `E_ALL & ~E_DEPRECATED`.',
            'configuration.php — log_errors, error_reporting_level',
        );
    }
}
