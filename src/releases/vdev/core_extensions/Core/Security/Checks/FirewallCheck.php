<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Security\Checks;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\ISecurityCheck;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\SecurityFinding;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/**
 * Pare-feu applicatif des Spaces.
 *
 * Le WAF est un réglage par Space, avec trois modes. Le contrôle signale les
 * Spaces sans protection, sans décider à la place de l'administrateur : un mode
 * d'observation reste un choix légitime pendant une mise au point.
 */
final class FirewallCheck extends BaseHelper implements ISecurityCheck
{
    public function group(): string
    {
        return 'Pare-feu';
    }

    public function check(): array
    {
        // Le réglage vit dans la configuration du Space, sérialisée en JSON.
        $rows = $this->db->select('SELECT id, title, params FROM spaces ORDER BY id ASC');

        $disabled = [];
        $observing = [];

        foreach ($rows as $row) {
            $params = is_string($row->params ?? null) ? json_decode($row->params, true) : null;
            $mode = is_array($params) ? (string) ($params['waf_mode'] ?? '') : '';

            if ($mode === 'block') {
                continue;
            }

            if ($mode === 'observe') {
                $observing[] = (string) $row->title;
                continue;
            }

            $disabled[] = (string) $row->title;
        }

        if ($disabled === [] && $observing === []) {
            return [new SecurityFinding(
                'Pare-feu applicatif',
                SecurityFinding::OK,
                'Le pare-feu applicatif bloque dans tous les Spaces.',
            )];
        }

        $findings = [];

        if ($disabled !== []) {
            $findings[] = new SecurityFinding(
                'Pare-feu désactivé',
                SecurityFinding::DANGER,
                'Le pare-feu applicatif est désactivé pour : ' . implode(', ', $disabled) . '.',
                'Activez le pare-feu dans la configuration de ces Spaces.',
                'Administration — Extensions — Sécurité',
            );
        }

        if ($observing !== []) {
            $findings[] = new SecurityFinding(
                'Pare-feu en observation',
                SecurityFinding::INFO,
                'Le pare-feu applicatif observe sans bloquer pour : ' . implode(', ', $observing) . '.',
                null,
                'Administration — Extensions — Sécurité',
            );
        }

        return $findings;
    }
}
