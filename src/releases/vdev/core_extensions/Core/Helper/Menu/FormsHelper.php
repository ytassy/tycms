<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Menu;

use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;
use YTassy\Tycms\CoreExts\Core\Models\Menu;
use YTassy\Tycms\CoreExts\Core\Models\MenuItem;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

class FormsHelper extends BaseHelper
{
    use TraitCoreAdditionalHelpers;

    public function getMenuItemForm(Menu $menu, ?MenuItem $item): Form
    {
        $menusById = $this->coreMenuAdminHelper->getMenusAndItemsOfSpace($menu->space_id);
        $menuWithItems = $menusById[$menu->id] ?? $menu;

        $typeOptions = [];
        foreach ($this->coreMenuRouteHelper->getAllMenusDef() as $key => $definition) {
            $typeOptions[$key] = $definition->ui_name !== ''
                ? $definition->ui_name
                : $definition->extension . ' / ' . $definition->type;
        }

        $parentOptions = [['value' => null, 'label' => 'À la racine du menu']];
        foreach ($menuWithItems->items as $candidate) {
            if ($candidate->id === $item?->id) {
                continue;
            }

            $parentOptions[] = [
                'value' => $candidate->id,
                'label' => $candidate->title,
            ];
        }

        $spaces = $this->coreSpaceHelper->getAllSpacesByIds();
        $roleOptions = [['value' => 0, 'label' => 'Aucun rôle requis']];
        foreach ($this->coreSpaceHelper->getRolesForSpace($menu->space_id) as $role) {
            $spaceTitle = $spaces[$role->space_id]->title ?? ('Espace #' . $role->space_id);
            $roleOptions[] = [
                'value' => $role->id,
                'label' => $spaceTitle . ' - ' . $role->name,
            ];
        }

        return (new Form($this))
            ->setId('core.menu-item.edit')
            ->setLabel('Élément de menu')
            ->addSection('general', 'Général')
            ->addField('general', 'title', 'core.text', [
                'label' => 'Titre',
                'required' => true,
                'validation' => ['minLength' => 1, 'maxLength' => 100],
            ])
            ->addField('general', 'alias', 'core.text', [
                'label' => 'Alias',
                'description' => 'Ce champs est requis pour définir l\'url d\'une page.',
                'validation' => ['maxLength' => 100],
            ])
            ->addField('general', 'definitionKey', 'core.select', [
                'label' => 'Type',
                'required' => true,
                'placeholder' => 'Sélectionner un type',
                'options' => $typeOptions,
            ])
            ->addField('general', 'parent_id', 'core.select', [
                'label' => 'Parent',
                'options' => $parentOptions,
            ])
            ->addField('general', 'status', 'core.select', [
                'label' => 'Statut',
                'default' => 1,
                'required' => true,
                'options' => [
                    ['value' => 1, 'label' => 'Publié'],
                    ['value' => 0, 'label' => 'Dépublié'],
                    ['value' => -1, 'label' => 'Archivé'],
                    ['value' => -2, 'label' => 'Corbeille'],
                ],
            ])
            ->addField('general', 'display_in_menu', 'core.switch', [
                'label' => 'Afficher dans le menu',
                'default' => true,
            ])
            ->addSection('presentation', 'Présentation et référencement')
            ->addField('presentation', 'access_role_id', 'core.select', [
                'label' => 'Rôle d’accès',
                'default' => 0,
                'options' => $roleOptions,
            ])
            ->addField('presentation', 'icon', 'core.icon-picker', [
                'label' => 'Icône',
                'validation' => ['maxLength' => 100],
            ])
            ->addField('presentation', 'css', 'core.text', [
                'label' => 'Classe CSS',
                'validation' => ['maxLength' => 100],
            ])
            ->addField('presentation', 'page_title', 'core.text', [
                'label' => 'Titre de page',
                'validation' => ['maxLength' => 100],
            ])
            ->addField('presentation', 'browser_title', 'core.text', [
                'label' => 'Titre navigateur',
                'validation' => ['maxLength' => 100],
            ])
            ->addField('presentation', 'meta_robots', 'core.select', [
                'label' => 'Meta robots',
                'default' => 'index, follow',
                'required' => true,
                'options' => [
                    'index, follow' => 'index, follow',
                    'noindex, follow' => 'noindex, follow',
                    'index, nofollow' => 'index, nofollow',
                    'noindex, nofollow' => 'noindex, nofollow',
                ],
            ])
            ->addField('presentation', 'meta_description', 'core.textarea', [
                'label' => 'Meta description',
                'validation' => ['maxLength' => 1_000],
            ]);
    }

    public function getMenuForm(?Menu $menu): Form
    {
        $form = (new Form($this))
            ->setId($menu === null ? 'core.menu.create' : 'core.menu.edit')
            ->setLabel('Menu')
            ->setHideSingleSectionTab()
            ->addSection('general', 'Général');

        if ($menu === null) {
            $spaceOptions = [];
            foreach ($this->coreAclHelper->getSpacesHavingGlobalPermission(Permissions::MENU_CRUD) as $spaceId) {
                $space = $this->coreSpaceHelper->getSpace($spaceId);
                if ($space !== null) {
                    $spaceOptions[] = ['value' => $space->id, 'label' => $space->title];
                }
            }

            $form->addField('general', 'space_id', 'core.select', [
                'label' => 'Espace',
                'required' => true,
                'placeholder' => 'Sélectionner un espace',
                'options' => $spaceOptions,
            ]);
        } else {
            $form->addField('general', 'space_title', 'core.text', [
                'label' => 'Espace',
                'disabled' => true,
                'submittable' => false,
            ]);
        }

        return $form
            ->addField('general', 'name', 'core.text', [
                'label' => 'Nom',
                'required' => true,
                'validation' => ['minLength' => 1, 'maxLength' => 100],
            ])
            ->addField('general', 'alias', 'core.text', [
                'label' => 'Alias',
                'description' => 'L’alias est utilisé comme préfixe des URL des éléments du menu.',
                'validation' => ['maxLength' => 100],
            ])
            ->addField('general', 'description', 'core.textarea', [
                'label' => 'Description',
                'validation' => ['maxLength' => 1_000],
            ]);
    }


    public function getMenuItemParamsForm(string $definitionKey): ?Form
    {
        $definition = $this->coreMenuRouteHelper->getAllMenusDef()[$definitionKey] ?? null;
        if ($definition === null || $definition->paramsFormDefinition === null) {
            return null;
        }

        return Form::fromArray($this, $definition->paramsFormDefinition);
    }
}
