<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Security;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/**
 * Registre des contrôles d'analyse de sécurité.
 *
 * Les extensions déclarent leurs contrôles ; Core en possède un jeu par défaut.
 * Rien n'est persisté : le registre se remplit à chaque démarrage et les
 * constats sont recalculés à la lecture du bilan.
 *
 * Les collisions d'identifiant sont refusées, comme pour les autres registres
 * du CMS : deux contrôles ne peuvent pas partager le même nom.
 */
final class SecurityCheckRegistry extends BaseHelper
{
    /** @var array<string, ISecurityCheck> */
    private array $checks = [];

    /** @var array<string, string> */
    private array $owners = [];

    /**
     * Enregistre un contrôle sous un identifiant stable.
     *
     * @param string $id Identifiant qualifié, par exemple `plg_core.media-root-only`.
     */
    public function register(string $id, ISecurityCheck $check, string $owner = 'plg_core'): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_.-]*$/D', $id) !== 1) {
            throw new \InvalidArgumentException('Identifiant de contrôle de sécurité invalide : ' . $id);
        }

        if (isset($this->checks[$id])) {
            throw new \InvalidArgumentException('Contrôle de sécurité déjà enregistré : ' . $id);
        }

        $this->checks[$id] = $check;
        $this->owners[$id] = $owner;
    }

    /** @return array<string, ISecurityCheck> */
    public function all(): array
    {
        return $this->checks;
    }

    /**
     * Exécute tous les contrôles et regroupe leurs constats.
     *
     * Un contrôle qui lève une exception ne doit pas priver l'administrateur du
     * reste du bilan : l'échec devient un constat d'alerte dans son propre
     * groupe, sans exposer la cause interne.
     *
     * @return array{groups: list<array<string, mixed>>, summary: array<string, int>}
     */
    public function report(): array
    {
        $groups = [];

        foreach ($this->checks as $id => $check) {
            try {
                $findings = $check->check();
                $group = $check->group();
            } catch (\Throwable $exception) {
                $this->logException($exception);

                $findings = [new SecurityFinding(
                    'Contrôle indisponible',
                    SecurityFinding::DANGER,
                    'Ce contrôle n’a pas pu être exécuté ; consultez le journal du site.',
                )];
                $group = 'Autres';
            }

            foreach ($findings as $finding) {
                if (!$finding instanceof SecurityFinding) {
                    continue;
                }

                $groups[$group][] = $finding->toArray() + ['statusRank' => $finding->rank()];
            }
        }

        return [
            'groups' => self::present($groups),
            'summary' => self::summary($groups),
        ];
    }

    /**
     * Met en forme les groupes : constats triés par gravité, compteurs.
     *
     * @param array<string, list<array<string, mixed>>> $groups
     * @return list<array<string, mixed>>
     */
    private static function present(array $groups): array
    {
        $presented = [];

        foreach ($groups as $label => $findings) {
            // Tri stable : à gravité égale, l'ordre de déclaration est conservé.
            usort($findings, static fn (array $left, array $right): int => $left['statusRank'] <=> $right['statusRank']);

            $counts = [SecurityFinding::CRITICAL => 0, SecurityFinding::DANGER => 0, SecurityFinding::INFO => 0, SecurityFinding::OK => 0];

            foreach ($findings as $finding) {
                $counts[$finding['status']]++;
            }

            $presented[] = [
                'id' => strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $label) ?? $label),
                'label' => $label,
                // Le rang de tri sert au client ; il n'est pas un statut.
                'findings' => array_map(
                    static function (array $finding): array {
                        unset($finding['statusRank']);

                        return $finding;
                    },
                    $findings,
                ),
                'counts' => $counts,
            ];
        }

        // Les groupes les plus préoccupants d'abord.
        usort($presented, static function (array $left, array $right): int {
            if ($left['counts'][SecurityFinding::CRITICAL] !== $right['counts'][SecurityFinding::CRITICAL]) {
                return $right['counts'][SecurityFinding::CRITICAL] <=> $left['counts'][SecurityFinding::CRITICAL];
            }

            return $right['counts'][SecurityFinding::DANGER] <=> $left['counts'][SecurityFinding::DANGER];
        });

        return $presented;
    }

    /**
     * @param array<string, list<array<string, mixed>>> $groups
     * @return array<string, int>
     */
    private static function summary(array $groups): array
    {
        $summary = [SecurityFinding::CRITICAL => 0, SecurityFinding::DANGER => 0, SecurityFinding::INFO => 0, SecurityFinding::OK => 0];

        foreach ($groups as $findings) {
            foreach ($findings as $finding) {
                $summary[$finding['status']]++;
            }
        }

        return $summary;
    }
}
