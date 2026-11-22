<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\SsoProvider;

use YTassy\Tycms\Lib\Forms\Form;
use YTassy\Tycms\CoreExts\Core\Models\SsoProvider;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

class FormsHelper extends BaseHelper
{
    /** @param list<array{value:int,label:string}> $spaceOptions */
    public function getSsoProviderForm(?SsoProvider $provider, array $spaceOptions): Form
    {
        $form = (new Form($this))
            ->setId($provider === null ? 'core.sso_provider.create' : 'core.sso_provider.edit')
            ->setLabel('Fournisseur SSO')
            ->addSection('general', 'Général')
            ->addField('general', 'space_id', 'core.select', [
                'label' => 'Espace propriétaire', 'required' => true,
                'description' => 'Le fournisseur pourra être proposé dans cet espace et ses descendants. Ce choix est définitif après création.',
                'options' => $spaceOptions, 'disabled' => $provider !== null,
            ])
            ->addField('general', 'protocol', 'core.select', [
                'label' => 'Protocole', 'required' => true,
                'description' => 'OIDC sert à la connexion ; OAuth 2.0 sert aux intégrations par utilisateur.',
                'options' => ['oidc' => 'OpenID Connect', 'oauth2' => 'OAuth 2.0'],
                'default' => 'oidc', 'disabled' => $provider !== null,
            ])
            ->addField('general', 'name', 'core.text', [
                'label' => 'Nom', 'required' => true, 'validation' => ['maxLength' => 150],
            ])
            ->addField('general', 'enabled', 'core.switch', [
                'label' => 'Fournisseur activé',
                'description' => 'Pour apparaître à la connexion, le fournisseur doit aussi être sélectionné dans l’éditeur de l’espace. Les intégrations apparaissent dans la section « Mes intégrations » du composant Mon compte.',
            ])
            ->addSection('capabilities', 'Capacités')
            ->addField('capabilities', 'login_enabled', 'core.switch', [
                'label' => 'Autoriser la connexion',
                'description' => 'Réservé à OIDC, avec le pilote Google ou le pilote générique.',
            ])
            ->addField('capabilities', 'integration_enabled', 'core.switch', [
                'label' => 'Autoriser les intégrations',
                'description' => 'Permet aux utilisateurs d’autoriser leur propre compte externe via OAuth 2.0.',
            ])
            ->addField('capabilities', 'auto_link_account', 'core.switch', [
                'label' => 'Autoriser le rattachement automatique',
                'description' => 'Nécessite aussi l’autorisation de l’espace. Si la configuration de l\'espace exige un email vérifié, le mapping email_verified doit être configuré dans ce fournisseur.',
            ])
            ->addField('capabilities', 'login_button_text', 'core.text', [
                'label' => 'Texte du bouton de connexion', 'validation' => ['maxLength' => 150],
            ])
            ->addField('capabilities', 'register_button_text', 'core.text', [
                'label' => 'Texte du bouton d’inscription', 'validation' => ['maxLength' => 150],
            ])
            ->addSection('configuration', 'Configuration JSON')
            ->addNote('configuration', 'Format attendu', 'Saisissez deux objets JSON. La configuration contient les paramètres non secrets ; le mapping OIDC désigne les attributs d’identité vérifiés.')
            ->addField('configuration', 'config_json', 'core.code', [
                'label' => 'Configuration', 'required' => true, 'syntax' => 'json',
                'description' => 'Générique : driver, client_id, authorization_endpoint, token_endpoint, redirect_uri(s), scopes. OIDC ajoute issuer et jwks_uri. Aucun secret ni jeton ici.',
                'default' => '{}',
                'validation' => ['maxBytes' => 32768],
            ])
            ->addField('configuration', 'mapping_json', 'core.code', [
                'label' => 'Mapping', 'required' => true, 'syntax' => 'json',
                'description' => 'Pour OIDC, indiquez les chemins claims.* de l’ID token vérifié, dont email_verified pour le mode de rattachement 1. OAuth 2.0 simple utilise {}.',
                'default' => '{}',
                'validation' => ['maxBytes' => 32768],
            ])
            ->addSection('secret', 'Secret client');

        if ($provider !== null) {
            $form->addField('secret', 'secret_action', 'core.select', [
                'label' => 'Action sur le secret', 'required' => true,
                'description' => 'Le secret actuel n’est jamais affiché. Choisissez explicitement son remplacement ou sa suppression.',
                'default' => 'keep',
                'options' => ['keep' => 'Conserver', 'replace' => 'Remplacer', 'remove' => 'Supprimer'],
            ]);
        }
        $form->addField('secret', 'client_secret', 'core.text', [
            'label' => 'Nouveau secret client',
            'description' => 'Saisi uniquement lors de la création ou du remplacement ; il sera chiffré par le serveur.',
            'ui' => ['inputType' => 'password'],
            'validation' => ['maxBytes' => 8192],
        ]);
        return $form;
    }

}
