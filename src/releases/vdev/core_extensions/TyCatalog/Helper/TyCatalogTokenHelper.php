<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\TyCatalog\Helper;

use DateTimeImmutable;
use DateTimeZone;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\TyCatalog\Exception\PublicationException;
use YTassy\Tycms\CoreExts\TyCatalog\Models\Catalog;
use YTassy\Tycms\CoreExts\TyCatalog\Models\CatalogToken;
use YTassy\Tycms\CoreExts\TyCatalog\Traits\TraitTyCatalogHelpers;
use YTassy\Tycms\Exceptions\AccessDeniedException;

/**
 * Jetons de publication distante.
 *
 * Le jeton en clair n'est retourné qu'à la création ; la base ne contient que
 * son SHA-256. Un jeton est limité à un catalogue et à des droits explicites.
 */
final class TyCatalogTokenHelper extends BaseHelper
{
    use TraitTyCatalogHelpers;

    private const TOKEN_PATTERN = '/\Atycat_[a-f0-9]{64}\z/';

    /**
     * @param list<string> $abilities
     * @return array{id:int,token:string,abilities:list<string>,expires_at:?string}
     */
    public function create(
        Catalog $catalog,
        string $label,
        array $abilities,
        ?int $userId,
        ?int $expiresInDays = null,
    ): array {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 150) {
            throw new PublicationException('Le libellé du jeton est obligatoire, avec 150 caractères maximum.');
        }

        $abilities = array_values(array_unique($abilities));
        sort($abilities);
        if ($abilities === [] || array_diff($abilities, CatalogToken::ABILITIES) !== []) {
            throw new PublicationException('Droits du jeton invalides. Utiliser deposit et/ou publish.');
        }

        $token = 'tycat_' . bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);
        $expiresAt = null;
        if ($expiresInDays !== null && $expiresInDays > 0) {
            $expiresAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
                ->modify('+' . $expiresInDays . ' days')
                ->format('Y-m-d H:i:s');
        }

        $this->db->execute(
            'INSERT INTO ty_catalog_tokens
                (catalog_id, label, token_hash, abilities, expires_at, created_by)
             VALUES (%, %, %, %, %, %)',
            [$catalog->id, $label, $hash, implode(',', $abilities), $expiresAt, $userId],
        );

        return [
            'id' => $this->db->getLastInsertId(),
            'token' => $token,
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function listForCatalog(Catalog $catalog): array
    {
        $tokens = $this->db->selectOrm(
            CatalogToken::class,
            'SELECT * FROM ty_catalog_tokens WHERE catalog_id = % ORDER BY created DESC, id DESC',
            [$catalog->id],
        );

        return array_map(static fn (CatalogToken $token): array => [
            'id' => $token->id,
            'label' => $token->label,
            'abilities' => $token->abilityList(),
            'enabled' => $token->enabled,
            'expires_at' => $token->expires_at?->format(DATE_ATOM),
            'last_used_at' => $token->last_used_at?->format(DATE_ATOM),
            'created' => $token->created->format(DATE_ATOM),
            'revoked_at' => $token->revoked_at?->format(DATE_ATOM),
        ], $tokens);
    }

    public function revoke(Catalog $catalog, int $tokenId): void
    {
        $this->db->execute(
            'UPDATE ty_catalog_tokens
             SET enabled = 0, revoked_at = CURRENT_TIMESTAMP
             WHERE id = % AND catalog_id = % AND revoked_at IS NULL',
            [$tokenId, $catalog->id],
        );

        $existing = $this->db->selectOrmOneOrNull(
            CatalogToken::class,
            'SELECT * FROM ty_catalog_tokens WHERE id = % AND catalog_id = %',
            [$tokenId, $catalog->id],
        );
        if ($existing === null) {
            throw new PublicationException('Jeton de publication introuvable.');
        }
    }

    /**
     * Authentifier un en-tête Authorization Bearer et rendre le jeton avec son
     * catalogue. Toute cause d'échec produit le même refus.
     *
     * @return array{token:CatalogToken,catalog:Catalog}
     */
    public function authenticate(string $authorization, int $catalogId, string $ability): array
    {
        if (!preg_match('/\ABearer\s+(tycat_[a-f0-9]{64})\z/i', trim($authorization), $matches)) {
            throw new AccessDeniedException();
        }

        $token = strtolower($matches[1]);
        $hash = hash('sha256', $token);
        $row = $this->db->selectOrmOneOrNull(
            CatalogToken::class,
            'SELECT * FROM ty_catalog_tokens WHERE token_hash = % AND catalog_id = %',
            [$hash, $catalogId],
        );

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        if ($row === null || !$row->enabled || $row->revoked_at !== null
            || ($row->expires_at !== null && $row->expires_at <= $now)
            || !in_array($ability, $row->abilityList(), true)
            || !hash_equals($row->token_hash, $hash)) {
            throw new AccessDeniedException();
        }

        $catalog = $this->tyCatalogAdminHelper->get($catalogId);
        if ($catalog === null) {
            throw new AccessDeniedException();
        }

        $this->db->execute(
            'UPDATE ty_catalog_tokens SET last_used_at = CURRENT_TIMESTAMP WHERE id = %',
            [$row->id],
        );

        return ['token' => $row, 'catalog' => $catalog];
    }
}
