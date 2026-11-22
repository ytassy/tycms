<?php

namespace YTassy\Tycms\CoreExts\Core\WF;

use InvalidArgumentException;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\Core\Models\SsoProvider;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Sso\SsoProviderSettings;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

final class WFSsoProvider extends BaseWF
{
    use TraitAllHelpers;

    /** @return array{spaces:list<array<string,mixed>>,providers:list<array<string,mixed>>} */
    public function listForAdministration(): array
    {
        $spaceIds = $this->allowedSpaceIds();
        $spaces = $this->coreSpaceHelper->getAllSpacesByIds();
        $items = [];
        foreach ($this->coreSsoProviderHelper->getProvidersOwnedBySpaces($spaceIds) as $provider) {
            $items[] = [
                'id' => (int) $provider->id,
                'space_id' => (int) $provider->space_id,
                'space_title' => $spaces[(int) $provider->space_id]->title ?? '#' . $provider->space_id,
                'name' => $provider->name,
                'protocol' => $provider->protocol,
                'enabled' => (bool) $provider->enabled,
                'login_enabled' => (bool) $provider->login_enabled,
                'integration_enabled' => (bool) $provider->integration_enabled,
                'login_available' => $this->coreSsoProviderHelper->isLoginAvailable($provider),
                'integration_available' => $this->coreSsoProviderHelper->isIntegrationAvailable($provider),
                'has_secret' => is_string($provider->secret_data) && $provider->secret_data !== '',
            ];
        }
        return [
            'spaces' => array_values(array_map(static fn ($id) => [
                'id' => $id, 'title' => $spaces[$id]->title,
            ], $spaceIds)),
            'providers' => $items,
        ];
    }

    /** @return array<string,mixed> */
    public function getEditorForm(?int $providerId): array
    {
        $provider = $providerId === null ? null : $this->getAllowedProvider($providerId);
        $spaceIds = $this->allowedSpaceIds();
        $spaces = $this->coreSpaceHelper->getAllSpacesByIds();
        $options = array_map(static fn ($id) => [
            'value' => $id, 'label' => $spaces[$id]->title . ' (#' . $id . ')',
        ], $spaceIds);
        $form = $this->coreSsoProviderFormsHelper->getSsoProviderForm($provider, $options);
        $values = $provider === null ? [] : [
            'space_id' => (int) $provider->space_id,
            'protocol' => $provider->protocol,
            'name' => $provider->name,
            'enabled' => (bool) $provider->enabled,
            'login_enabled' => (bool) $provider->login_enabled,
            'integration_enabled' => (bool) $provider->integration_enabled,
            'auto_link_account' => (bool) $provider->auto_link_account,
            'login_button_text' => $provider->login_button_text,
            'register_button_text' => $provider->register_button_text,
            'config_json' => $this->prettyJson($provider->config),
            'mapping_json' => $this->prettyJson($provider->mapping),
            'secret_action' => 'keep',
            'client_secret' => '',
        ];
        return [
            'form' => $form->getDefForRia($values),
            'hasSecret' => $provider !== null && is_string($provider->secret_data) && $provider->secret_data !== '',
        ];
    }

    public function create(object $data): int
    {
        $spaceIds = $this->allowedSpaceIds();
        $values = $this->validatedValues($data, null, $spaceIds);
        $spaceId = (int) $values->space_id;
        if (!in_array($spaceId, $spaceIds, true)) {
            ExceptionResponse::throwDanger('Vous ne pouvez pas créer un fournisseur dans cet espace.');
        }
        return $this->coreSsoProviderHelper->createProvider($this->normalise($values, null));
    }

    public function update(int $providerId, object $data): int
    {
        $provider = $this->getAllowedProvider($providerId);
        $values = $this->validatedValues($data, $provider, $this->allowedSpaceIds());
        if ((int) $values->space_id !== (int) $provider->space_id || $values->protocol !== $provider->protocol) {
            ExceptionResponse::throwWarning('L’espace propriétaire et le protocole ne peuvent pas être modifiés.');
        }
        $this->coreSsoProviderHelper->updateProvider($providerId, $this->normalise($values, $provider));
        return $providerId;
    }

    /** @param list<int> $spaceIds */
    private function validatedValues(object $data, ?SsoProvider $provider, array $spaceIds): object
    {
        $spaces = $this->coreSpaceHelper->getAllSpacesByIds();
        $options = array_map(static fn ($id) => [
            'value' => $id, 'label' => $spaces[$id]->title . ' (#' . $id . ')',
        ], $spaceIds);
        $form = $this->coreSsoProviderFormsHelper->getSsoProviderForm($provider, $options);
        $result = $form->validateSubmittedValues($data);
        if (!$result->isValid()) {
            ExceptionResponse::throwWarning('Données du fournisseur invalides : ' . ($result->getFirstError()?->message ?? 'valeur non autorisée.'));
        }
        return (object) $form->getInitialValues(get_object_vars($result->value));
    }

    /** @return array<string,mixed> */
    private function normalise(object $values, ?SsoProvider $provider): array
    {
        $name = trim($values->name);
        if ($name === '') ExceptionResponse::throwWarning('Le nom est obligatoire.');
        if ($values->protocol === 'oauth2' && ($values->login_enabled || $values->auto_link_account)) {
            ExceptionResponse::throwWarning('OAuth 2.0 seul ne permet ni la connexion utilisateur ni le rattachement de compte.');
        }
        if ($values->protocol === 'oidc' && $values->integration_enabled) {
            ExceptionResponse::throwWarning('Pour une intégration, créez un fournisseur OAuth 2.0 distinct.');
        }
        try {
            $config = SsoProviderSettings::parseObject($values->config_json, 'Configuration');
            $mapping = SsoProviderSettings::parseObject($values->mapping_json, 'Mapping');
        } catch (InvalidArgumentException $error) {
            ExceptionResponse::throwWarning($error->getMessage());
        }
        $action = $provider === null ? 'replace' : $values->secret_action;
        $secret = $provider?->secret_data;
        if ($action === 'remove') $secret = null;
        if ($action === 'replace') {
            if ($values->client_secret === '') {
                if ($provider !== null) ExceptionResponse::throwWarning('Saisissez le nouveau secret client.');
                $secret = null;
            } else {
                $secret = $this->coreCryptHelper->encrypt($values->client_secret, 'provider:v1');
            }
        } elseif ($values->client_secret !== '') {
            ExceptionResponse::throwWarning('Choisissez « Remplacer » pour enregistrer un nouveau secret.');
        }
        if ($values->enabled && ($values->login_enabled || $values->integration_enabled)) {
            try {
                if (($config->driver ?? null) === 'google' && $values->protocol === 'oidc') {
                    if ($values->login_enabled) SsoProviderSettings::assertActiveGoogle((array) $config, (array) $mapping);
                } else {
                    SsoProviderSettings::assertActiveGeneric((array) $config, $values->protocol);
                    if ($values->login_enabled) SsoProviderSettings::assertOidcMapping((array) $mapping);
                }
            } catch (InvalidArgumentException $error) {
                ExceptionResponse::throwWarning($error->getMessage());
            }
            if ($values->login_enabled && ($secret === null || $secret === '')) {
                ExceptionResponse::throwWarning('Un secret client est nécessaire pour activer la connexion OIDC.');
            }
        }
        return [
            'space_id' => (int) $values->space_id,
            'protocol' => $values->protocol,
            'name' => $name,
            'enabled' => (int) $values->enabled,
            'login_enabled' => (int) $values->login_enabled,
            'integration_enabled' => (int) $values->integration_enabled,
            'auto_link_account' => (int) $values->auto_link_account,
            'config' => json_encode($config, JSON_THROW_ON_ERROR),
            'mapping' => json_encode($mapping, JSON_THROW_ON_ERROR),
            'secret_data' => $secret,
            'login_button_text' => trim($values->login_button_text),
            'register_button_text' => trim($values->register_button_text),
        ];
    }

    private function getAllowedProvider(int $providerId): SsoProvider
    {
        $provider = $providerId > 0 ? $this->coreSsoProviderHelper->getProvider($providerId) : null;
        if ($provider === null) ExceptionResponse::throwWarning('Fournisseur introuvable.');
        if (!$this->coreAclHelper->hasGlobalPermission(Permissions::SSO_CRUD, (int) $provider->space_id)) {
            ExceptionResponse::throwDanger('Vous ne pouvez pas administrer ce fournisseur.');
        }
        return $provider;
    }

    /** @return list<int> */
    private function allowedSpaceIds(): array
    {
        $ids = $this->coreAclHelper->getSpacesHavingGlobalPermission(Permissions::SSO_CRUD);
        if ($ids === []) ExceptionResponse::throwDanger('Vous ne pouvez pas administrer les fournisseurs SSO.');
        return $ids;
    }

    private function prettyJson(string $json): string
    {
        $decoded = json_decode($json, false);
        return $decoded instanceof \stdClass
            ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            : $json;
    }
}
