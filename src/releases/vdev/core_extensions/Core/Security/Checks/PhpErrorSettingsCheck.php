<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Security\Checks;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\ISecurityCheck;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\SecurityFinding;

/**
 * Réglages PHP des erreurs réellement actifs pendant la requête.
 *
 * Afficher les erreurs dans la réponse expose les chemins, les requêtes et les
 * détails techniques aux visiteurs. Les masquer sans les journaliser rend en
 * revanche toute panne difficile à diagnostiquer.
 *
 * Le contrôle lit les valeurs effectives avec `ini_get()` et `error_reporting()`,
 * puis les compare à `configuration.php`. Un hébergement mutualisé qui interdit
 * ces réglages est donc signalé, avec une correction dans `php.ini` ou
 * `.user.ini` au lieu d'une correction sans effet dans la configuration locale.
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

        $configuredDisplay = (bool) ($configuration->display_errors ?? true);
        $configuredLog = (bool) ($configuration->log_errors ?? false);
        $configuredLevel = (int) ($configuration->error_reporting_level ?? 0);

        // Lire l'état réellement appliqué : c'est lui que voit le visiteur.
        $displayErrors = $this->effectiveFlag('display_errors', $configuredDisplay);
        $logErrors = $this->effectiveFlag('log_errors', $configuredLog);
        $level = $this->effectiveLevel($configuredLevel);

        return [
            $this->displayFinding($configuredDisplay, $displayErrors),
            $this->traceabilityFinding($configuredLog, $configuredLevel, $logErrors, $level),
        ];
    }

    /**
     * Valeur effective d'un booléen PHP, avec repli sur la configuration.
     *
     * `filter_var()` traite notamment `stderr` comme désactivé : les erreurs
     * sont alors détournées hors de la réponse HTTP et ne sont pas exposées.
     */
    private function effectiveFlag(string $option, bool $fallback): bool
    {
        if (!function_exists('ini_get')) {
            return $fallback;
        }

        $value = ini_get($option);

        return $value === false ? $fallback : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /** Niveau effectif, avec repli sur l'ini puis sur la configuration. */
    private function effectiveLevel(int $fallback): int
    {
        if (function_exists('error_reporting')) {
            return error_reporting();
        }

        if (function_exists('ini_get')) {
            $value = ini_get('error_reporting');

            if ($value !== false && $value !== '') {
                return (int) $value;
            }
        }

        return $fallback;
    }

    private function displayFinding(bool $configuredDisplay, bool $effectiveDisplay): SecurityFinding
    {
        if (!$effectiveDisplay) {
            return new SecurityFinding(
                'Affichage des erreurs PHP',
                SecurityFinding::OK,
                'Les erreurs PHP ne sont pas affichées dans les réponses.',
            );
        }

        // La configuration est déjà sûre : l'hébergement refuse donc de l'appliquer.
        if (!$configuredDisplay) {
            return new SecurityFinding(
                'Affichage des erreurs PHP',
                SecurityFinding::DANGER,
                'Les erreurs PHP sont affichées dans la réponse. Un visiteur peut voir des chemins '
                . 'de fichiers, des requêtes SQL ou d’autres détails techniques du serveur.',
                'L’hébergement impose l’affichage malgré `display_errors = false`. Autorisez `ini_set()` '
                . 'ou fixez `display_errors = Off` dans `php.ini` ou `.user.ini`.',
                'php.ini — display_errors',
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

    private function traceabilityFinding(
        bool $configuredLog,
        int $configuredLevel,
        bool $effectiveLog,
        int $effectiveLevel
    ): SecurityFinding {
        $levelCoversErrors = ($effectiveLevel & self::REQUIRED_LEVELS) === self::REQUIRED_LEVELS;

        if ($effectiveLog && $levelCoversErrors) {
            return new SecurityFinding(
                'Journalisation des erreurs PHP',
                SecurityFinding::OK,
                'Les erreurs et avertissements PHP sont écrits dans le journal du serveur.',
            );
        }

        $problems = [];
        $fixes = [];
        $hostOverride = false;

        if (!$effectiveLog) {
            $problems[] = 'l’écriture dans le journal du serveur est désactivée (`log_errors`)';

            if ($configuredLog) {
                $hostOverride = true;
            } else {
                $fixes[] = 'Activez `log_errors`.';
            }
        }

        if (!$levelCoversErrors) {
            $problems[] = 'le niveau effectif exclut les erreurs ou les avertissements (`error_reporting`)';

            if (($configuredLevel & self::REQUIRED_LEVELS) === self::REQUIRED_LEVELS) {
                $hostOverride = true;
            } else {
                $fixes[] = 'Utilisez un niveau incluant `E_ERROR` et `E_WARNING`, '
                    . 'par exemple `E_ALL & ~E_DEPRECATED`.';
            }
        }

        if ($hostOverride) {
            $fixes[] = 'L’hébergement n’applique pas la configuration : autorisez `ini_set()` et '
                . '`error_reporting()`, ou fixez ces valeurs dans `php.ini` ou `.user.ini`.';
        }

        return new SecurityFinding(
            'Journalisation des erreurs PHP incomplète',
            SecurityFinding::INFO,
            'Une panne peut devenir difficile à diagnostiquer : ' . implode(' et ', $problems) . '.',
            implode(' ', $fixes),
            $hostOverride
                ? 'php.ini (ou .user.ini) — log_errors, error_reporting'
                : 'configuration.php — log_errors, error_reporting_level',
        );
    }
}