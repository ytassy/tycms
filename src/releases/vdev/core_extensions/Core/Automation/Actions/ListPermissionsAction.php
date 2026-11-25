<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Automation\Actions;

use YTassy\Tycms\CoreExts\Automation\ExtensionApi\ActionContext;
use YTassy\Tycms\CoreExts\Automation\ExtensionApi\BaseAutomationAction;
use YTassy\Tycms\CoreExts\Core\WF\WFRole;

/**
 * Catalogue des permissions assignables, pour écrire un rôle correct.
 *
 * Un rôle est défini par un tableau de permissions. Sans la liste exacte, une
 * IA inventerait des identifiants ; le WF les refuse ensuite (« Permission
 * inconnue »), mais après un aller-retour coûteux et sans savoir quoi écrire.
 *
 * Le catalogue vient de `WFRole::getDirectory()`, qui filtre déjà par Space et
 * par droit de gestion : on n'expose rien que l'appelant ne puisse attribuer.
 */
final class ListPermissionsAction extends BaseAutomationAction
{
    public function execute(ActionContext $context, array $params): mixed
    {
        try {
            /** @var WFRole $workflow */
            $workflow = $this->getSharedExtensionsClass(WFRole::class);
            $directory = $workflow->getDirectory();

            $permissions = [];

            foreach ($directory['permissions'] ?? [] as $permission) {
                // Une permission sans Space manageable ne peut pas être
                // attribuée par cet appelant : la proposer serait trompeur.
                if (($permission['manageable_space_ids'] ?? []) === []) {
                    continue;
                }

                $permissions[] = [
                    'id' => $permission['name'],
                    'label' => $permission['label'],
                    'description' => $permission['description'] ?? '',
                    'group' => $permission['group'] ?? '',
                    'tab' => $permission['tab'] ?? '',
                    'requires' => $permission['required_permissions'] ?? [],
                    'spaceIds' => $permission['manageable_space_ids'],
                ];
            }

            usort($permissions, static fn (array $left, array $right): int => strcmp($left['id'], $right['id']));

            return [
                'permissions' => $permissions,
                'count' => count($permissions),
                'spaces' => array_map(
                    static fn (array $space): array => [
                        'id' => $space['id'],
                        'title' => $space['title'],
                        'canCreateRole' => $space['can_create'],
                    ],
                    $directory['spaces'] ?? [],
                ),
            ];
        } catch (\Throwable $exception) {
            throw $this->actionException($exception, $context);
        }
    }
}
