<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\WF;

use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\CoreExts\TyCatalog\Models\Catalog;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogLink;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogToken;
use YTassy\Tycms\CoreExts\TyCatalog\Permissions;
use YTassy\Tycms\CoreExts\TyCatalog\Traits\TraitTyCatalogHelpers;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

/**
 * Administration des catalogues hébergés.
 *
 * Les permissions sont globales au Space propriétaire : la délégation par
 * catalogue n'est pas retenue pour cette version. ROOT peut lister, éditer et
 * déplacer les catalogues de tous les Espaces ; les autres comptes restent
 * limités au Space courant. Le WF relit le Space réel avant toute écriture ; le
 * navigateur ne fournit jamais un space_id sans contrôle.
 */
final class WFTyCatalogAdmin extends BaseWF
{
    use TraitAllHelpers;
    use TraitTyCatalogHelpers;

    private const MAX_LINKS = 50;

    /** @return array<string, mixed> */
    public function getOverview(): array
    {
        $space = $this->coreSpaceHelper->getCurrentSpace();
        $spaceId = (int) $space->id;
        $isRoot = $this->coreAclHelper->isRoot();

        if (!$isRoot && !$this->coreAclHelper->hasGlobalPermission(Permissions::VIEW, $spaceId)) {
            throw new AccessDeniedException();
        }

        // ROOT administre les catalogues de tous les Espaces. Les autres comptes
        // restent strictement limités au Space courant.
        $linksByCatalog = $isRoot
            ? $this->tyCatalogAdminHelper->linksForAll()
            : $this->tyCatalogAdminHelper->linksForSpace($spaceId);
        $spaceTitles = [];
        foreach ($this->coreSpaceHelper->getAllSpacesByIds() as $knownSpace) {
            $spaceTitles[$knownSpace->id] = $knownSpace->title;
        }

        $catalogs = [];
        $source = $isRoot
            ? $this->tyCatalogAdminHelper->listAll()
            : $this->tyCatalogAdminHelper->listForSpace($spaceId);
        foreach ($source as $catalog) {
            $catalogs[] = $this->catalogView(
                $catalog,
                $linksByCatalog[$catalog->id] ?? [],
                $spaceTitles[$catalog->space_id] ?? ('#' . $catalog->space_id),
            );
        }

        // La racine privée et les clés de confiance restent sous contrôle ROOT.
        $canManageKeys = $isRoot
            || $this->coreAclHelper->hasGlobalPermission(Permissions::ADMIN, $spaceId);
        $canEdit = $isRoot
            || $this->coreAclHelper->hasGlobalPermission(Permissions::EDIT, $spaceId);

        $spaces = [];
        if ($isRoot) {
            foreach ($this->coreSpaceHelper->getAllSpacesByIds() as $knownSpace) {
                $spaces[] = ['id' => $knownSpace->id, 'title' => $knownSpace->title];
            }
            usort($spaces, static fn (array $first, array $second): int => strcasecmp($first['title'], $second['title']));
        }

        return [
            'space' => ['id' => $spaceId, 'title' => $space->title],
            'is_root' => $isRoot,
            'spaces' => $spaces,
            'can_edit' => $canEdit,
            'can_manage_keys' => $canManageKeys,
            'can_manage_tokens' => $canManageKeys,
            'storage_root' => $canManageKeys ? $this->app->getConf()->ty_catalog_storage_root : null,
            'catalogs' => $catalogs,
        ];
    }

    /**
     * Crée ou met à jour un catalogue et remplace ses références amies.
     *
     * ROOT peut éditer un catalogue de n'importe quel Espace et changer son
     * Espace propriétaire. Les autres comptes restent limités à leur Space courant.
     *
     * @return array{catalog_id:int}
     */
    public function save(object $data): array
    {
        $currentSpace = $this->coreSpaceHelper->getCurrentSpace();
        $currentSpaceId = (int) $currentSpace->id;
        $isRoot = $this->coreAclHelper->isRoot();

        $catalogId = isset($data->id) ? (int) $data->id : null;
        $catalog = $catalogId !== null ? $this->tyCatalogAdminHelper->get($catalogId) : null;
        if ($catalogId !== null && $catalog === null) {
            throw new AccessDeniedException();
        }

        // Space propriétaire réel avant écriture ; il sert de garde pour la
        // mise à jour et de source pour un déplacement.
        $sourceSpaceId = $catalog?->space_id ?? $currentSpaceId;
        if (!$isRoot) {
            if ($sourceSpaceId !== $currentSpaceId
                || !$this->coreAclHelper->hasGlobalPermission(Permissions::EDIT, $currentSpaceId)) {
                throw new AccessDeniedException();
            }
        }

        $requestedSpaceId = isset($data->spaceId) && $data->spaceId !== null ? (int) $data->spaceId : null;
        $targetSpaceId = $requestedSpaceId ?? $sourceSpaceId;
        if (!$isRoot && $targetSpaceId !== $currentSpaceId) {
            throw new AccessDeniedException();
        }

        // Un changement d'Espace doit désigner un Espace existant.
        $destination = $this->coreSpaceHelper->getSpacesByIds([$targetSpaceId])[$targetSpaceId] ?? null;
        if ($targetSpaceId !== $sourceSpaceId && $destination === null) {
            ExceptionResponse::throwWarning('L’Espace de destination est introuvable.');
        }

        $values = [
            'name' => $this->name($data->name ?? null),
            'slug' => $this->slug($data->slug ?? null),
            'description' => $this->description($data->description ?? null),
            'enabled' => (bool) ($data->enabled ?? false),
            'keep_versions' => $this->retention($data->keep_versions ?? null),
            'keep_archives' => $this->retention($data->keep_archives ?? null),
        ];

        if ($this->tyCatalogAdminHelper->slugExists($values['slug'], $catalogId)) {
            ExceptionResponse::throwWarning('Ce slug est déjà utilisé par un autre catalogue.');
        }

        $links = $this->submittedLinks($data->links ?? []);
        $keys = $this->keysFromSubmission($data, $targetSpaceId);

        $savedId = $this->tyCatalogAdminHelper->save(
            $sourceSpaceId,
            $catalogId,
            $values,
            $links,
            $this->session->getUserId(),
            $keys,
            $targetSpaceId,
        );

        if ($catalogId !== null && $sourceSpaceId !== $targetSpaceId) {
            $this->logInfo(
                'Catalogue déplacé vers un autre Espace.',
                ['catalog_id' => $savedId, 'from_space_id' => $sourceSpaceId, 'to_space_id' => $targetSpaceId],
                'TY_CATALOG_CATALOG_MOVED',
                'extensions',
            );
        }

        // Le journal ne contient ni URL fournie ni clé publique.
        $this->logInfo(
            $catalogId === null ? 'Catalogue créé.' : 'Catalogue modifié.',
            ['catalog_id' => $savedId, 'space_id' => $targetSpaceId, 'links' => count($links)],
            'TY_CATALOG_CATALOG_SAVED',
            'extensions',
        );

        return ['catalog_id' => $savedId];
    }

    /** @return array{extension:array<string,mixed>} */
    public function saveExtensionFiche(object $data): array
    {
        $catalog = $this->editableCatalog((int) ($data->catalogId ?? 0));
        $identifier = is_string($data->identifier ?? null) ? trim($data->identifier) : '';
        if (!preg_match('/\A[a-z0-9_]{1,80}\z/', $identifier)) {
            ExceptionResponse::throwWarning('Identifiant d’extension invalide.');
        }

        $name = $this->name($data->displayName ?? null);
        $description = is_string($data->description ?? null) ? trim($data->description) : '';
        if (mb_strlen($description) > 2000) {
            ExceptionResponse::throwWarning('La description de l’extension ne peut pas dépasser 2 000 caractères.');
        }

        $fiche = $this->tyCatalogAdminHelper->saveExtensionFiche(
            $catalog->id,
            $identifier,
            $name,
            $description,
            $this->imageUrl($data->imageUrl ?? null),
        );
        if ($fiche === null) {
            ExceptionResponse::throwWarning('Extension introuvable dans ce catalogue.');
        }

        $this->logInfo(
            'Fiche d’extension mise à jour.',
            ['catalog_id' => $catalog->id, 'extension' => $identifier],
            'TY_CATALOG_EXTENSION_SAVED',
            'extensions',
        );

        return ['extension' => [
            'identifier' => $fiche->extension_identifier,
            'name' => $fiche->display_name,
            'description' => $fiche->description,
            'image_url' => $fiche->image_url,
        ]];
    }

    /** @return array{tokens:list<array<string,mixed>>,abilities:list<string>} */
    public function getTokens(int $catalogId): array
    {
        $catalog = $this->managedCatalog($catalogId);

        return [
            'tokens' => $this->tyCatalogTokenHelper->listForCatalog($catalog),
            'abilities' => CatalogToken::ABILITIES,
        ];
    }

    /** @return array{id:int,token:string,abilities:list<string>,expires_at:?string} */
    public function createToken(object $data): array
    {
        $catalog = $this->managedCatalog((int) ($data->catalogId ?? 0));
        $label = is_string($data->label ?? null) ? trim($data->label) : '';
        $abilities = is_array($data->abilities ?? null)
            ? array_values(array_filter($data->abilities, static fn (mixed $value): bool => is_string($value)))
            : [];
        $days = isset($data->days) ? (int) $data->days : 365;
        if ($days < 0 || $days > 3650) {
            ExceptionResponse::throwWarning('La durée du jeton doit être comprise entre 0 et 3 650 jours.');
        }

        $result = $this->tyCatalogTokenHelper->create(
            $catalog,
            $label,
            $abilities,
            $this->session->getUserId(),
            $days > 0 ? $days : null,
        );

        $this->logInfo(
            'Jeton de publication créé.',
            ['catalog_id' => $catalog->id, 'token_id' => $result['id']],
            'TY_CATALOG_TOKEN_CREATED',
            'extensions',
        );

        return $result;
    }

    public function revokeToken(object $data): void
    {
        $catalog = $this->managedCatalog((int) ($data->catalogId ?? 0));
        $tokenId = (int) ($data->tokenId ?? 0);
        if ($tokenId < 1) {
            ExceptionResponse::throwWarning('Jeton de publication invalide.');
        }

        $this->tyCatalogTokenHelper->revoke($catalog, $tokenId);
        $this->logInfo(
            'Jeton de publication révoqué.',
            ['catalog_id' => $catalog->id, 'token_id' => $tokenId],
            'TY_CATALOG_TOKEN_REVOKED',
            'extensions',
        );
    }

    private function editableCatalog(int $catalogId): Catalog
    {
        $spaceId = (int) $this->coreSpaceHelper->getCurrentSpace()->id;
        $isRoot = $this->coreAclHelper->isRoot();
        if (!$isRoot && !$this->coreAclHelper->hasGlobalPermission(Permissions::EDIT, $spaceId)) {
            throw new AccessDeniedException();
        }

        $catalog = $this->tyCatalogAdminHelper->get($catalogId);
        if ($catalog === null || (!$isRoot && $catalog->space_id !== $spaceId)) {
            throw new AccessDeniedException();
        }

        return $catalog;
    }

    /** Création et révocation des jetons : configuration sensible, ADMIN sur l’Espace ou ROOT. */
    private function managedCatalog(int $catalogId): Catalog
    {
        $spaceId = (int) $this->coreSpaceHelper->getCurrentSpace()->id;
        $isRoot = $this->coreAclHelper->isRoot();
        if (!$isRoot && !$this->coreAclHelper->hasGlobalPermission(Permissions::ADMIN, $spaceId)) {
            throw new AccessDeniedException();
        }

        $catalog = $this->tyCatalogAdminHelper->get($catalogId);
        if ($catalog === null || (!$isRoot && $catalog->space_id !== $spaceId)) {
            throw new AccessDeniedException();
        }

        return $catalog;
    }

    /** @param list<CatalogLink> $links @return array<string, mixed> */
    private function catalogView(Catalog $catalog, array $links, string $spaceTitle): array
    {
        return [
            'id' => $catalog->id,
            'space_id' => $catalog->space_id,
            'space_title' => $spaceTitle,
            'slug' => $catalog->slug,
            'name' => $catalog->name,
            'description' => $catalog->description,
            'enabled' => $catalog->enabled,
            'keep_versions' => $catalog->keep_versions,
            'keep_archives' => $catalog->keep_archives,
            'key_id' => $catalog->key_id,
            'public_key' => $catalog->public_key,
            'created' => $catalog->created->format(DATE_ATOM),
            'updated' => $catalog->updated?->format(DATE_ATOM),
            'links' => array_map(static fn (CatalogLink $link): array => [
                'id' => $link->id,
                'name' => $link->name,
                'target_url' => $link->target_url,
                'description' => $link->description,
                'position' => $link->position,
            ], $links),
        ];
    }

    private function name(mixed $value): string
    {
        $name = is_string($value) ? trim($value) : '';
        if ($name === '' || mb_strlen($name) > 150) {
            ExceptionResponse::throwWarning('Le nom du catalogue est obligatoire, avec 150 caractères maximum.');
        }

        return $name;
    }

    private function slug(mixed $value): string
    {
        $slug = is_string($value) ? trim($value) : '';
        if ($slug === '' || strlen($slug) > 80 || !preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $slug)) {
            ExceptionResponse::throwWarning('Le slug doit être composé de lettres minuscules, de chiffres et de tirets simples.');
        }

        return $slug;
    }

    private function description(mixed $value): string
    {
        $description = is_string($value) ? trim($value) : '';
        if (mb_strlen($description) > 5000) {
            ExceptionResponse::throwWarning('La description ne peut pas dépasser 5 000 caractères.');
        }

        return $description;
    }

    private function retention(mixed $value): int
    {
        $value = is_int($value) ? $value : (is_string($value) && ctype_digit($value) ? (int) $value : 0);
        if ($value < 1 || $value > 100) {
            ExceptionResponse::throwWarning('Les limites de conservation doivent être comprises entre 1 et 100.');
        }

        return $value;
    }

    private function imageUrl(mixed $value): ?string
    {
        $url = is_string($value) ? trim($value) : '';
        if ($url === '') {
            return null;
        }
        if (strlen($url) > 2000) {
            ExceptionResponse::throwWarning('L’URL de l’image est trop longue.');
        }

        $parts = parse_url($url);
        $host = is_array($parts) ? strtolower((string) ($parts['host'] ?? '')) : '';
        $local = in_array($host, ['localhost', '127.0.0.1', '[::1]'], true);
        if (!is_array($parts) || $host === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || !in_array($parts['scheme'] ?? '', $local ? ['https', 'http'] : ['https'], true)) {
            ExceptionResponse::throwWarning(
                'L’URL de l’image doit être absolue, en HTTPS, sans identifiant ni fragment.',
            );
        }

        return $url;
    }

    /** @return list<array{name:string,target_url:string,description:string,position:int}> */
    private function submittedLinks(mixed $links): array
    {
        if (!is_array($links)) {
            ExceptionResponse::throwWarning('La liste des références est invalide.');
        }
        if (count($links) > self::MAX_LINKS) {
            ExceptionResponse::throwWarning('Un catalogue ne peut pas déclarer plus de ' . self::MAX_LINKS . ' références.');
        }

        $values = [];
        $seenUrls = [];

        foreach ($links as $link) {
            $row = is_object($link) ? get_object_vars($link) : (is_array($link) ? $link : null);
            if ($row === null) {
                ExceptionResponse::throwWarning('Une référence est invalide.');
            }

            $name = isset($row['name']) && is_string($row['name']) ? trim($row['name']) : '';
            if ($name === '' || mb_strlen($name) > 150) {
                ExceptionResponse::throwWarning('Chaque référence doit avoir un nom de 150 caractères maximum.');
            }

            $targetUrl = $this->targetUrl($row['target_url'] ?? null);
            if (isset($seenUrls[mb_strtolower($targetUrl)])) {
                ExceptionResponse::throwWarning('La même URL est présente plusieurs fois parmi les références.');
            }
            $seenUrls[mb_strtolower($targetUrl)] = true;

            $description = isset($row['description']) && is_string($row['description']) ? trim($row['description']) : '';
            if (mb_strlen($description) > 2000) {
                ExceptionResponse::throwWarning('La description d’une référence ne peut pas dépasser 2 000 caractères.');
            }

            $position = isset($row['position']) ? (int) $row['position'] : 0;
            if ($position < 0 || $position > 9999) {
                ExceptionResponse::throwWarning('La position d’une référence doit être comprise entre 0 et 9 999.');
            }

            $values[] = [
                'name' => $name,
                'target_url' => $targetUrl,
                'description' => $description,
                'position' => $position,
            ];
        }

        return $values;
    }

    private function targetUrl(mixed $value): string
    {
        $url = is_string($value) ? trim($value) : '';
        $parts = parse_url($url);
        if ($url === '' || strlen($url) > 500 || !is_array($parts)
            || ($parts['scheme'] ?? '') !== 'https'
            || !isset($parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            ExceptionResponse::throwWarning(
                'Chaque référence doit être une URL HTTPS absolue, sans identifiant, requête ni fragment.',
            );
        }

        return $url;
    }

    /**
     * Les clés de confiance ne sont modifiables que par ROOT. Une soumission
     * d'un autre compte les ignore : elle ne peut ni les lire ni les écraser.
     *
     * @return array{key_id:?string,public_key:?string}|null
     */
    private function keysFromSubmission(object $data, int $spaceId): ?array
    {
        // ROOT gère les clés de tous les Espaces ; les autres comptes gardent
        // le contrôle ADMIN sur l’Espace cible.
        if (!$this->coreAclHelper->isRoot()
            && !$this->coreAclHelper->hasGlobalPermission(Permissions::ADMIN, $spaceId)) {
            return null;
        }

        // Absence des deux champs : conserver les clés existantes.
        if (!property_exists($data, 'key_id') && !property_exists($data, 'public_key')) {
            return null;
        }

        $keyId = $this->nullableString($data->key_id ?? null);
        $publicKey = $this->nullableString($data->public_key ?? null);

        if ($keyId === null && $publicKey === null) {
            return ['key_id' => null, 'public_key' => null];
        }

        if ($keyId === null || !preg_match('/\Aextensions-[a-zA-Z0-9_-]{1,53}\z/', $keyId)) {
            ExceptionResponse::throwWarning('L’identifiant de clé doit commencer par « extensions- ».');
        }
        if ($publicKey === null) {
            ExceptionResponse::throwWarning('La clé publique doit accompagner son identifiant.');
        }

        $bytes = base64_decode($publicKey, true);
        if ($bytes === false || strlen($bytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || base64_encode($bytes) !== $publicKey) {
            ExceptionResponse::throwWarning('La clé publique Ed25519 doit être encodée en base64.');
        }

        return ['key_id' => $keyId, 'public_key' => $publicKey];
    }

    private function nullableString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }
}
