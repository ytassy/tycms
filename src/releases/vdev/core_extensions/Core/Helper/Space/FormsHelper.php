<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Space;

use YTassy\Tycms\Extensions\BaseTemplate;
use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Roles;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\UserGroups;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

class FormsHelper extends BaseHelper
{
    use TraitCoreAdditionalHelpers;

    public function getSpaceForm(?Space $space, bool $canAdmin, bool $canEdit): Form
    {
        $templateOptions = [];
        foreach ($this->app->getExtensions() as $name => $extension) {
            if ($extension instanceof BaseTemplate) {
                $templateOptions[] = ['value' => $name, 'label' => $extension->getName()];
            }
        }

        $form = (new Form($this))
            ->setId($space === null ? 'core.space.create' : 'core.space.edit')
            ->setLabel('Espace')
            ->addSection('general', 'Général');

        if ($canAdmin) {
            $form
                ->addField('general', 'parent_id', 'core.select', $this->getParentField($space))
                ->addField('general', 'template', 'core.select', [
                    'label' => 'Template',
                    'required' => true,
                    'options' => $templateOptions,
                ])
                ->addField('general', 'hosts', 'core.textarea', [
                    'label' => 'Hôtes',
                    'description' => 'Un nom de domaine par ligne. Les hôtes déterminent l’espace utilisé pour une requête.',
                    'ui' => ['rows' => 3],
                    'validation' => ['maxLength' => 1_000],
                ]);
        }

        if ($canAdmin) {
            $form->addField('general', 'medias_dir', 'core.text', [
                'label' => 'Dossier des médias',
                'description' => $space === null
                    ? 'Nom unique : lettres, chiffres, tirets et underscores uniquement.'
                    : 'Lettres, chiffres, tirets et underscores uniquement. Le dossier sera renommé avec ses fichiers. Les anciennes URL et références ne seront pas modifiées : corrigez-les manuellement.',
                'required' => true,
                'validation' => ['minLength' => 1, 'maxLength' => 50],
            ]);
        }
        if ($space !== null) {
            $form->addField('general', 'logo', 'core.media_file', [
                'label' => 'Logo', 'mediaType' => 'image', 'allowManualInput' => false,
                'description' => 'Facultatif. Choisir une image dans les médias de cet espace.',
            ]);
        }

        $form
            ->addField('general', 'title', 'core.text', [
                'label' => 'Nom',
                'required' => true,
                'validation' => ['minLength' => 1, 'maxLength' => 50],
            ])
            ->addField('general', 'main_host', 'core.text', [
                'label' => 'Domaine principal',
                 'description' => 'Le domaine principal est obligatoire pour l\'envoi des emails.',
                'validation' => ['maxLength' => 253],
            ])
            ->addField('general', 'short_description', 'core.text', [
                'label' => 'Description courte',
                'validation' => ['maxLength' => 255],
            ])
            ->addField('general', 'description', 'core.rich_text', [
                'label' => 'Description longue',
                'validation' => ['maxLength' => 5_000],
            ])
            ->addField('general', 'timezone', 'core.timezone', [
                'label' => 'Fuseau horaire',
                'required' => true,
            ])
            ->addSection('seo', 'Référencement')
            ->addField('seo', 'page_browser_title', 'core.text', [
                'label' => 'Titre navigateur',
                'validation' => ['maxLength' => 255],
            ])
            ->addField('seo', 'page_browser_description', 'core.textarea', [
                'label' => 'Description navigateur',
                'validation' => ['maxLength' => 1_000],
            ])
            ->addField('seo', 'page_meta_robots', 'core.select', [
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
            ->addSection('mail', 'SMTP')
            ->addField('mail', 'mail_online', 'core.switch', [
                'label' => 'Envoi d’e-mails activé',
                'default' => true,
            ])
            ->addField('mail', 'mailer', 'core.select', [
                'label' => 'Transport',
                'default' => 'smtp',
                'required' => true,
                'options' => ['smtp' => 'SMTP'],
            ])
            ->addField('mail', 'mail_from', 'core.text', [
                'label' => 'Adresse expéditeur',
                'validation' => ['maxLength' => 255],
            ])
            ->addField('mail', 'mail_from_name', 'core.text', [
                'label' => 'Nom expéditeur',
                'validation' => ['maxLength' => 255],
            ])
            ->addField('mail', 'mail_smtp_auth', 'core.switch', [
                'label' => 'Authentification SMTP',
                'default' => true,
            ])
            ->addField('mail', 'mail_smtp_user', 'core.text', [
                'label' => 'Utilisateur SMTP',
                'validation' => ['maxLength' => 255],
            ])
            ->addField('mail', 'mail_smtp_pass', 'core.text', [
                'label' => 'Mot de passe SMTP',
                'description' => 'Laisser vide pour conserver le mot de passe actuel.',
                'ui' => ['inputType' => 'password'],
                'validation' => ['maxLength' => 255],
            ])
            ->addField('mail', 'mail_smtp_host', 'core.text', [
                'label' => 'Serveur SMTP',
                'validation' => ['maxLength' => 255],
            ])
            ->addField('mail', 'mail_smtp_secure', 'core.select', [
                'label' => 'Sécurité SMTP',
                'default' => 'ssl',
                'required' => true,
                'options' => ['ssl' => 'SSL', 'tls' => 'TLS', '' => 'Aucune'],
            ])
            ->addField('mail', 'mail_smtp_port', 'core.number', [
                'label' => 'Port SMTP',
                'default' => 465,
                'required' => true,
                'validation' => ['minimum' => 1, 'maximum' => 65535],
            ]);

        $form->addSection('sso', 'Connexion et inscription');
        if ($canAdmin) {
            $form->addField('sso', 'registration_auto_member_role', 'core.switch', [
                'label' => 'Attribuer automatiquement le rôle Membre à l’inscription ou à la création d’un compte',
                'description' => 'S’applique au formulaire, au SSO, à l’adhésion et à la création administrative. Si désactivé, configurez les rôles ou groupes pour accorder la permission MEMBER sur cet espace. Sans cette permission, la création des comptes utilisateur sera impossible et interdite.',
                'default' => true,
            ]);

            // Une cible déjà configurée reste affichable si les droits sur celle-ci ont changé.
            $params = is_object($space?->params) ? (array) $space->params : ($space?->params ?? []);
            $currentTargetId = $params['registration_target_space_id'] ?? null;
            $targetOptions = [['value' => null, 'label' => 'Cet espace']];
            foreach ($this->coreSpaceHelper->getAllSpacesByIds() as $candidate) {
                if ($candidate->id === $space?->id) continue;
                $canAdminTarget = $this->coreAclHelper->hasGlobalPermission(Permissions::SPACE_ADMIN, $candidate->id);
                if (!$canAdminTarget && $candidate->id !== $currentTargetId) continue;
                $targetOptions[] = [
                    'value' => $candidate->id,
                    'label' => $canAdminTarget ? $candidate->title : '#' . $candidate->id . ' (cible actuelle)',
                ];
            }
            $form->addField('sso', 'registration_target_space_id', 'core.select', [
                'label' => 'Espace principal des nouveaux inscrits',
                'description' => 'Le compte est rattaché à cet espace. Les adhésions, rôles, groupes et e-mails restent ceux de l’espace d’inscription. Sans cible, cet espace est utilisé.',
                'default' => null,
                'options' => $targetOptions,
            ]);
            $form->addField('sso', 'sso_allow_reset_local_password', 'core.switch', [
                'label' => 'Autoriser la récupération du mot de passe des comptes liés au SSO',
                'description' => 'S’applique aux comptes dont cet espace est l’espace principal, même si la demande vient d’un autre espace.',
                'default' => false,
            ]);
        }
        if ($canAdmin || $canEdit) {
            $form->addField('sso', 'registration_enabled', 'core.switch', [
                'label' => 'Autoriser les inscriptions',
                'description' => 'Autorise la création d’un compte et l’adhésion d’un compte existant par formulaire ou SSO.',
                'default' => false,
            ]);
        }
        if ($canEdit) {
            $form->addField('sso', 'sso_auto_link_existing_account', 'core.select', [
                'label' => 'Rattacher automatiquement un compte existant',
                'description' => 'Le fournisseur doit aussi autoriser ce rattachement. Le mode 2 accepte une adresse non vérifiée.',
                'default' => 0,
                'options' => [
                    ['value' => 0, 'label' => 'Désactivé'],
                    ['value' => 1, 'label' => 'Si l’e-mail est vérifié par le fournisseur'],
                    ['value' => 2, 'label' => 'Toujours, sans vérifier l’e-mail'],
                ],
            ]);
        }

        if ($canAdmin && $space !== null) {
            // Seul un administrateur peut définir des droits attribués automatiquement.
            // Le schéma ne propose que les espaces qu'il administre également.
            $roleSchema = $this->getRegistrationRoleSchema($space);
            $form->addField('sso', 'registration_roles', 'core.json', [
                'label' => 'Rôles à l’inscription',
                'description' => 'Rôles attribués lors de l’inscription par formulaire ou SSO.',
                'schema' => $roleSchema,
            ]);
            $form->addField('sso', 'user_roles', 'core.json', [
                'label' => 'Rôles utilisateur',
                'description' => 'Rôles également attribués à l’inscription, à l’adhésion et à la création administrative.',
                'schema' => $roleSchema,
            ]);

            // Les groupes sont attachés à l'espace d'inscription. Les groupes
            // personnels et système ne peuvent jamais être sélectionnés.
            $groupOptions = [];
            foreach ($this->coreUserGroupAdminHelper->getGroups() as $group) {
                if ($group->space_id === $space->id && !in_array($group->id, UserGroups::getAllIds(), true)) {
                    $groupOptions[] = ['value' => $group->id, 'label' => $group->name];
                }
            }
            if ($groupOptions === []) {
                $form->addNote('sso', 'Groupes à l’inscription',
                    'Créez d’abord un groupe ordinaire dans cet espace pour configurer son attribution.');
            } else {
                foreach (['registration_groups' => 'Groupes à l’inscription',
                    'user_groups' => 'Groupes utilisateur'] as $setting => $label) {
                    $form->addField('sso', $setting, 'core.select', [
                        'label' => $label,
                        'description' => 'Groupes de cet espace attribués lors de l’inscription ou de l’adhésion.',
                        'multiple' => true,
                        'default' => [],
                        'options' => $groupOptions,
                        'validation' => ['uniqueItems' => true],
                        'ui' => ['picker' => 'dropdown-list', 'addLabel' => 'Ajouter un groupe'],
                    ]);
                }
            }

            $providerOptions = [];
            $providerHelper = $this->coreSsoProviderHelper;
            $providers = $providerHelper->getProvidersForSpace($space);
            $spaces = $this->coreSpaceHelper->getAllSpacesByIds();
            foreach ($providers as $provider) {
                $status = match (true) {
                    (int) $provider->enabled !== 1 => 'désactivé',
                    $provider->protocol !== 'oidc' => strtoupper($provider->protocol) . ', sans bouton de connexion',
                    (int) $provider->login_enabled !== 1 => 'connexion désactivée',
                    $providerHelper->isLoginAvailable($provider) => 'connexion active',
                    default => 'configuration de connexion incomplète',
                };
                $providerOptions[] = [
                    'value' => (int) $provider->id,
                    'label' => $provider->name . ' — '
                        . ($spaces[(int) $provider->space_id]->title ?? '#' . $provider->space_id)
                        . ' — ' . $status,
                ];
            }
            if ($providerOptions !== []) {
                $form->addField('sso', 'sso_provider_ids', 'core.select', [
                    'label' => 'Fournisseurs proposés sur la connexion',
                    'description' => 'Sélection multiple. L’ordre choisi détermine celui des boutons ; les fournisseurs sans connexion active restent invisibles sur la page de connexion.',
                    'multiple' => true,
                    'default' => [],
                    'options' => $providerOptions,
                    'validation' => ['uniqueItems' => true],
                    'ui' => ['size' => min(8, max(3, count($providerOptions)))],
                ]);
            } else {
                $form->addNote('sso', 'Fournisseurs SSO', 'Aucun fournisseur n’est défini dans cet espace ou ses ancêtres.');
            }
        } elseif ($canAdmin) {
            $form->addNote('sso', 'Fournisseurs SSO', 'Créez d’abord l’espace, puis choisissez ses fournisseurs dans le formulaire de modification.');
        }

        return $form;
    }

    /**
     * Seul le Space racine peut rester sans parent ; tout autre Space en choisit un.
     *
     * @return array<string,mixed>
     */
    private function getParentField(?Space $space): array
    {
        $isRoot = $space !== null && $space->id === 1;
        $parentOptions = $isRoot ? [['value' => 0, 'label' => 'Aucun parent']] : [];

        if (!$isRoot) {
            foreach ($this->coreSpaceHelper->getAllSpacesByIds() as $candidate) {
                if ($candidate->id === $space?->id || !$this->coreAclHelper->hasGlobalPermission(Permissions::SPACE_ADMIN, $candidate->id)) {
                    continue;
                }
                $parentOptions[] = ['value' => $candidate->id, 'label' => $candidate->title];
            }
        }

        $field = [
            'label' => 'Espace parent',
            'description' => $isRoot
                ? 'L’espace racine reste sans parent.'
                : 'Obligatoire. Les permissions sont héritées de l’espace parent. Un utilisateur membre d’un espace est aussi membre de tous ses espaces enfants et peut y accéder sans nouvelle inscription.',
            'default' => $isRoot ? 0 : null,
            'required' => !$isRoot && $parentOptions !== [],
            'options' => $parentOptions,
        ];
        if (!$isRoot) $field['placeholder'] = 'Choisir un espace parent';

        return $field;
    }

    private function getRegistrationRoleSchema(Space $space): array
    {
        $properties = [];
        $allRoles = $this->coreAclHelper->getAllRoles();

        foreach ($this->coreSpaceHelper->getAllSpacesByIds() as $target) {
            if (!$this->coreAclHelper->hasGlobalPermission(Permissions::SPACE_ADMIN, $target->id)) {
                continue;
            }

            $ancestors = $this->coreSpaceHelper->getSpaceIdsWithParents($target->id);
            $roleIds = [];
            $labels = [];
            foreach ($allRoles as $role) {
                if (!in_array($role->space_id, $ancestors, true) || !in_array($role->type, [0, 2], true)
                    || Roles::isImplicit($role->id)) {
                    continue;
                }

                $roleIds[] = (int) $role->id;
                $labels[(int) $role->id] = $role->name . ' — ' . $role->space_title;
            }

            // Le préfixe évite une clé numérique, que le validateur PHP refuse dans properties.
            $key = $target->id === $space->id ? 'this' : 'space_' . $target->id;
            $properties[$key] = [
                'type' => 'array',
                '_ui_text' => $target->title . ' (#' . $target->id . ')',
                'items' => ['type' => 'integer', 'enum' => $roleIds, '_ui_texts' => $labels],
                'uniqueItems' => true,
            ];
        }

        return ['type' => 'object', 'properties' => $properties, 'additionalProperties' => false];
    }


}
