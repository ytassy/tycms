<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

/**
 * Génération, hachage, révocation et résolution des jetons d'API.
 *
 * Le secret est généré une seule fois et rendu au créateur ; seul son hachage
 * SHA-256 est conservé. La comparaison est en temps constant (`hash_equals`).
 */
final class ServiceAccountTokenHelper extends BaseHelper
{
    /** Génère un secret d'API : 64 caractères hexadécimaux. */
    public static function generateSecret(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hashSecret(string $secret): string
    {
        return hash('sha256', $secret);
    }

    public static function verifySecret(string $secret, string $storedHash): bool
    {
        return hash_equals($storedHash, self::hashSecret($secret));
    }

    /**
     * Crée un jeton nommé pour un compte de service.
     *
     * @return array{id: int, token: string} L'identifiant du jeton et son
     *         secret en clair, renvoyé une seule fois ; seul son hachage est
     *         conservé en base.
     */
    public function createToken(
        int $userId,
        string $name,
        ?int $spaceId = null,
        ?\DateTimeImmutable $expiresAt = null,
    ): array {
        $secret = self::generateSecret();

        $this->db->execute(
            'INSERT INTO api_tokens (user_id, name, token_hash, space_id, expires_at) VALUES (%, %, %, %, %)',
            [
                $userId,
                $name,
                self::hashSecret($secret),
                $spaceId,
                $expiresAt?->format('Y-m-d H:i:s'),
            ],
        );

        return ['id' => (int) $this->db->getLastInsertId(), 'token' => $secret];
    }

    /**
     * Révoque un jeton ; retourne false s'il n'existe pas ou s'il est déjà
     * révoqué. Le résultat vient de la mise à jour conditionnelle elle-même,
     * pas d'une lecture préalable qui pourrait mentir sous concurrence.
     */
    public function revoke(int $tokenId): bool
    {
        // La mise à jour conditionnelle renvoie le nombre de lignes modifiées :
        // zéro signifie « jeton absent » ou « déjà révoqué ».
        return $this->db->execute(
            'UPDATE api_tokens SET revoked_at = CURRENT_TIMESTAMP WHERE id = % AND revoked_at IS NULL',
            [$tokenId],
        ) > 0;
    }

    /**
     * Résout un secret vers le jeton actif correspondant.
     *
     * Un jeton révoqué, expiré ou inconnu retourne null. Le jeton retourné
     * porte son propriétaire et son Space d'exécution par défaut.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $secret): ?array
    {
        $row = $this->db->selectOneOrNull(
            'SELECT id, user_id, name, space_id, expires_at, last_used_at, revoked_at '
            . 'FROM api_tokens WHERE token_hash = % LIMIT 1',
            [self::hashSecret($secret)],
        );

        if ($row === null || $row->revoked_at !== null) {
            return null;
        }

        // Un jeton expiré n'est plus accepté ; l'instant de référence couvre
        // aussi les fuseaux de l'hébergement mutualisé.
        if ($row->expires_at !== null
            && new \DateTimeImmutable((string) $row->expires_at) < new \DateTimeImmutable('now')) {
            return null;
        }

        $this->db->execute('UPDATE api_tokens SET last_used_at = NOW() WHERE id = %', [(int) $row->id]);

        return [
            'id' => (int) $row->id,
            'user_id' => (int) $row->user_id,
            'name' => (string) $row->name,
            'space_id' => $row->space_id === null ? null : (int) $row->space_id,
        ];
    }
}
