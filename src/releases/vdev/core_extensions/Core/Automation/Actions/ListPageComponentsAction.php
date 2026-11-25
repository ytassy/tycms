<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;

/**
 * Catalogue des composants de page, pour un agent qui compose un document.
 *
 * Le JSON d'une page est un arbre de corps typés. Sans ce catalogue, l'IA ne
 * peut pas savoir quels `type` existent, quelles zones chacun accepte ni quels
 * `props` sont attendus : elle écrirait au hasard. Le registre porte déjà tout
 * cela, cette action ne fait que le projeter sans instancier de composant.
 */
final class ListPageComponentsAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            // Le catalogue est rempli par `onPrepareExtensions`, déclenché pour
            // tout parcours, routes ou non.
            $components = array_map(
                self::plainValue(...),
                $this->corePageComponentRegistry->catalogue(),
            );

            // Tri stable par identifiant : l'ordre du registre dépend du
            // chargement des extensions, pas de l'agent.
            usort(
                $components,
                static fn (array $left, array $right): int => strcmp($left['id'], $right['id']),
            );

            return [
                'components' => $components,
                'count' => count($components),
            ];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }

    /**
     * Réduit une valeur à ce que le langage sait transporter.
     *
     * Le catalogue contient des objets vides (`stdClass`) pour distinguer une
     * map vide d'une liste vide. La frontière de valeurs refuse tout objet
     * PHP : un objet vide devient donc une map vide, tout le reste étant
     * converti récursivement.
     */
    private static function plainValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        } elseif (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return $value;
        }

        $plain = [];

        foreach ($value as $key => $item) {
            $plain[$key] = self::plainValue($item);
        }

        return $plain;
    }
}
