<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Space;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Application;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Tools\ArrayTools;

class SpaceHelper extends BaseHelper
{
    private array $spaces;

    public function __construct(BaseApplicationItem|Application $app)
    {
        parent::__construct($app);

        $this->load();
    }

    public function load()
    {
        $spaces = ArrayTools::indexArray($this->db->selectOrm(Space::class, 'SELECT * FROM spaces'),
            'id');

        foreach($spaces as $space) {
            $mediasDir = $space->getMediasDir();
            if (!is_dir($mediasDir)) {
                if (!mkdir($mediasDir, 0775, true) && !is_dir($mediasDir)) {
                    throw new TechException("Impossible de créer le répertoire pour l'espace {$space->id}");
                }
            }
        }

        $this->spaces = $spaces;
    }


    /**
     * @return array<Space>
     */
    public function getDefaultSpacesFromContext(): array {

        $res = [];
        // Step 1 => Find Permission for Gest User
        $spaceIds = $this->coreAclHelper->getSpacesHavingGlobalPermission(Permissions::MEMBER);
        
        foreach ($spaceIds as $spaceId) $res[$spaceId] = $this->spaces[$spaceId];

        // Ajouter les Spaces correspondant à l'hôte de la requête.
        foreach ($this->getSpacesMatchingRequestHost() as $space) {
            $res[$space->id] = $space;
        }

        // Step 3 => Si RIEN alors on prends les spaces sans host
        if (count($res) == 0) { // Si rien on prend les Space sans Hosts
            foreach($this->spaces as $space){
                if (count($space->hosts) == 0) $res[$space->id] = $space;
            }
        }

        // Step 4 => Si toujours rien alors Espace Racine
        if (count($res) == 0) {
            $res[1] = $this->spaces[1];
        }

        return array_values($res);
    }

    /**
     * Fix the initial Space before menu routes are registered. An explicit
     * session choice always wins, including on another configured host.
     */
    public function resolveInitialSpace(): void
    {
        $selectedId = $this->session->getSpaceId();
        if ($selectedId !== null) {
            if (isset($this->spaces[$selectedId])) return;
            $this->session->setSpaceId(null);
        }

        $hostMatches = $this->getSpacesMatchingRequestHost();

        if (count($hostMatches) === 1) {
            $this->changeCurrentSpace($hostMatches[0]->id);
            return;
        }

        // More than one matching host still requires an explicit selection.
        if ($hostMatches !== []) return;

        $defaults = $this->getDefaultSpacesFromContext();
        if (count($defaults) === 1) $this->changeCurrentSpace($defaults[0]->id);
    }

    /** @return list<Space> */
    public function getSpacesMatchingRequestHost(): array
    {
        $host = $this->request?->host ?? '';
        if ($host === '') return [];

        // WebRequest sépare le port de l'hôte ; les domaines sans port restent compatibles.
        $hosts = [$host];
        if ($this->app->getConf()->dev && $host === 'localhost') {
            $hosts[] = $host . ':' . $this->request->port;
        }

        // Le même filtre alimente le choix explicite et la sélection initiale.
        $matches = [];
        foreach ($this->spaces as $space) {
            foreach ($hosts as $candidate) {
                if (in_array($candidate, $space->hosts, true)) {
                    $matches[] = $space;
                    break;
                }
            }
        }

        return $matches;
    }

    public function getSpace(?int $spaceId = null): ?Space {

        if (!is_int($spaceId) || !isset($this->spaces[$spaceId])) {

            $defaults = $this->getDefaultSpacesFromContext();

            if (count($defaults) == 1)
            {
                $spaceId = $defaults[0]->id;
                $this->changeCurrentSpace($spaceId);
            }
        }

        if ($spaceId == null) return null;
        return $this->spaces[$spaceId] ?? null;
    }

    public function getCurrentSpace(): Space {
        $currentSpaceId = $this->session->getSpaceId();
        if ($currentSpaceId == null) $currentSpaceId = 1;

        return $this->getSpace($currentSpaceId);
    }

    public function changeCurrentSpace(int $spaceId): void
    {
        if (!isset($this->spaces[$spaceId])) {
            throw new \InvalidArgumentException('Unknown space ID.');
        }

        $this->session->setSpaceId($spaceId);
    }

    /** @return array<int, int> */
    public function getSpaceIdsWithParents(?int $spaceId = null): array
    {
        if ($spaceId === null) $spaceId = $this->getCurrentSpace()->id;

        $spaces = [];
        $seenSpaceIds = [];
        while ($spaceId !== null && !isset($seenSpaceIds[$spaceId])) {
            $space = $this->spaces[$spaceId] ?? null;
            if ($space === null) break;

            $spaces[] = $space->id;
            $seenSpaceIds[$space->id] = true;
            $spaceId = $space->parent_id;
        }

        return $spaces;
    }

    /** @return array<int, ?int> */
    public function getSpaceParentIds(): array
    {
        $parentIds = [];
        foreach ($this->spaces as $space) {
            $parentIds[$space->id] = $space->parent_id;
        }

        return $parentIds;
    }

    /** @return array<int> */
    public function getAllSpaceIds(): array
    {
        return array_keys($this->spaces);
    }

    public function getRolesForSpace(?int $spaceId = null): array
    {
        $spaces = array_flip($this->getSpaceIdsWithParents($spaceId));

        $roles = [];
        foreach ($this->coreAclHelper->getAllRoles() as $roleId => $role) {
            if (isset($spaces[$role->space_id])) $roles[$roleId] = $role;
        }

        return $roles;
    }

    /** @param array<int> $spaceIds */
    public function getSpacesByIds(array $spaceIds): array
    {
        $spaces = [];
        foreach ($spaceIds as $spaceId) {
            if (isset($this->spaces[$spaceId])) {
                $spaces[$spaceId] = $this->spaces[$spaceId];
            }
        }

        return $spaces;
    }

     /** @return array<int,Space> */
    public function getAllSpacesByIds(): array
    {
        return $this->spaces;
    }
}
