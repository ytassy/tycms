<?php

namespace YTassy\Tycms\CoreExts\Security\Helper;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Models\Config;
use YTassy\Tycms\CoreExts\Security\SecurityPolicy;
use YTassy\Tycms\CoreExts\Security\Configuration;
use YTassy\Tycms\Exceptions\TechException;

/** Lecture par Space, sans héritage implicite ni confiance dans les paramètres du navigateur. */
class SecurityPolicyHelper extends BaseHelper
{
    /** @var array<int, SecurityPolicy> */
    private array $policies = [];

    private array $configurations = [];

    /** @var array<int, list<string>> */
    private array $formActions = [];

    public function configuration(int $spaceId): array
    {
        if (!isset($this->configurations[$spaceId])) {
            $config = $this->db->selectOrmOneOrNull(Config::class,
                'SELECT * FROM config WHERE extension = % AND space_id = %', ['plg_security', $spaceId]);
            if ($config === null) throw new TechException('Configuration Sécurité introuvable pour ce Space.');
            $this->configurations[$spaceId] = Configuration::normalize((array) $config->params);
        }
        return $this->configurations[$spaceId];
    }

    public function forSpace(int $spaceId): SecurityPolicy
    {
        if (!isset($this->policies[$spaceId])) {
            $this->policies[$spaceId] = new SecurityPolicy(array_intersect_key($this->configuration($spaceId), SecurityPolicy::defaults()));
        }
        return $this->policies[$spaceId];
    }

    public function forget(int $spaceId): void { unset($this->policies[$spaceId], $this->configurations[$spaceId], $this->formActions[$spaceId]); }

    /**
     * Origines des fournisseurs de connexion du Space courant pour form-action.
     * Le navigateur contrôle aussi la redirection du POST /sso/start vers l’IdP.
     *
     * @return list<string>
     */
    public function formActionsForCurrentSpace(): array
    {
        // Avant résolution d’une route, aucune réponse HTML ne peut porter le formulaire SSO.
        if ($this->app->getRoute() === null) return [];

        $space = $this->coreSpaceHelper->getCurrentSpace();
        if ($space === null) return [];

        if (!isset($this->formActions[$space->id])) {
            $origins = [];
            foreach ($this->coreSsoProviderHelper->getLoginProviders($space) as $provider) {
                $config = json_decode($provider->config, true);
                if (!is_array($config)) continue;

                // Google utilise l’URL fixe du client OAuth ; le pilote générique expose la sienne.
                $endpoint = ($config['driver'] ?? null) === 'google'
                    ? 'https://accounts.google.com/o/oauth2/v2/auth'
                    : ($config['authorization_endpoint'] ?? null);
                $origin = is_string($endpoint) ? SecurityPolicy::httpsOrigin($endpoint) : null;
                if ($origin !== null) $origins[$origin] = $origin;
            }
            $this->formActions[$space->id] = array_values($origins);
        }
        return $this->formActions[$space->id];
    }

    public function siteOrigin(): ?string
    {
        $request = $this->request;
        if ($request === null || !$request->is_https) return null;
        $host = $request->host;
        if (str_contains($host, ':') && !str_starts_with($host, '[')) $host = '[' . $host . ']';
        return SecurityPolicy::httpsOrigin('https://' . $host . ($request->port === 443 ? '' : ':' . $request->port));
    }

    public function allowsFrame(int $spaceId, string $url): bool
    {
        return $this->forSpace($spaceId)->allowsFrame($url, $this->siteOrigin());
    }

    public function responseSources(bool $administration): array
    {
        // Avant résolution d’une route, le Space et la session ne sont pas encore garantis.
        if ($this->app->getRoute() === null) return (new SecurityPolicy(SecurityPolicy::defaults()))->previewSources();
        $spaceId = $this->session->getSpaceId();
        if ($spaceId === null) return (new SecurityPolicy(SecurityPolicy::defaults()))->previewSources();
        $sources = $this->forSpace($spaceId)->previewSources();

        if ($administration) {
            // Un srcdoc hérite aussi de la CSP du parent : permettre les ressources des pages accessibles.
            // Chaque aperçu reçoit ensuite la politique précise du Space réel de la page.
            foreach ($this->coreAclHelper->getSpacesHavingGlobalPermission([Permissions::PAGE_READ, Permissions::PAGE_CRUD]) as $id) {
                foreach ($this->forSpace($id)->previewSources() as $resource => $allowed) {
                    $sources[$resource] = array_values(array_unique([...$sources[$resource], ...$allowed]));
                }
            }
            if (in_array('https:', $sources['iframe'], true)) $sources['iframe'] = ['https:'];
            else $sources['iframe'][] = 'https://embed.diagrams.net';
        }
        return $sources;
    }
}
