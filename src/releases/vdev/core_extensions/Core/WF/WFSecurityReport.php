<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\WF;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\SecurityCheckRegistry;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Traits\TraitCoreSharedHelpers;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

/**
 * Bilan d'analyse de sécurité du site.
 *
 * Le WorkFlow vérifie l'accès, puis délègue l'exécution des contrôles au
 * registre. Aucun constat n'est persisté : le bilan est recalculé à chaque
 * lecture et reflète donc l'état courant de la configuration.
 *
 * La lecture est réservée aux comptes ROOT : un bilan de sécurité décrit les
 * faiblesses du site et ne doit pas être consultable par un administrateur
 * ordinaire.
 */
final class WFSecurityReport extends BaseWF
{
    use TraitCoreSharedHelpers;

    /** @return array<string, mixed> */
    public function report(): array
    {
        if (!$this->coreAclHelper->isRoot()) {
            ExceptionResponse::throwDanger('Vous ne disposez pas des droits pour consulter le bilan de sécurité.');
        }

        $registry = $this->app->getOrAddSharedExtensionsClass(SecurityCheckRegistry::class);

        return $registry->report();
    }
}
