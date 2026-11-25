<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Security\Checks;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\ISecurityCheck;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\SecurityFinding;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/**
 * État des jetons d'API des comptes de service.
 *
 * Un jeton expiré mais non révoqué reste une ligne active ; un jeton jamais
 * utilisé est une autorisation créée pour rien. Les deux méritent d'être vus
 * sans être bloquants : le premier donne un accès fantôme potentiel, le second
 * élargit inutilement la surface.
 */
final class ApiTokensCheck extends BaseHelper implements ISecurityCheck
{
    public function group(): string
    {
        return 'Accès API';
    }

    public function check(): array
    {
        $expired = $this->db->selectOneOrNull(
            'SELECT COUNT(*) AS total FROM api_tokens WHERE revoked_at IS NULL AND expires_at IS NOT NULL AND expires_at < NOW()'
        );

        $unused = $this->db->selectOneOrNull(
            'SELECT COUNT(*) AS total FROM api_tokens WHERE revoked_at IS NULL AND last_used_at IS NULL'
        );

        $expiredCount = (int) ($expired->total ?? 0);
        $unusedCount = (int) ($unused->total ?? 0);

        if ($expiredCount === 0 && $unusedCount === 0) {
            return [new SecurityFinding(
                'Jetons d’API',
                SecurityFinding::OK,
                'Aucun jeton expiré ni inutilisé parmi les jetons actifs.',
            )];
        }

        $findings = [];

        if ($expiredCount > 0) {
            $findings[] = new SecurityFinding(
                'Jetons expirés encore actifs',
                SecurityFinding::DANGER,
                $expiredCount . ' jeton(s) ont dépassé leur date d’expiration sans être révoqués. '
                . 'Ils n’ouvrent plus l’API, mais restent comptés comme autorisations actives.',
                'Révoquez les jetons expirés depuis l’écran des comptes de service.',
                'Administration — Comptes de service',
            );
        }

        if ($unusedCount > 0) {
            $findings[] = new SecurityFinding(
                'Jetons jamais utilisés',
                SecurityFinding::INFO,
                $unusedCount . ' jeton(s) actifs n’ont jamais servi. Une autorisation créée '
                . 'pour rien élargit la surface d’accès.',
                'Supprimez les jetons qui ne servent pas.',
                'Administration — Comptes de service',
            );
        }

        return $findings;
    }
}
