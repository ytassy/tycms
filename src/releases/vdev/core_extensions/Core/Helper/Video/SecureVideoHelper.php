<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Video;

use RuntimeException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Models\Config;
use YTassy\Tycms\Tools\HtmlTools;

/** Résolution et lecture HLS partagées entre les pages et les futures extensions. */
final class SecureVideoHelper extends BaseHelper
{
    private const MAX_SOURCE = 2048;
    private const MAX_PLAYLIST = 1_000_000;
    private const MAX_SEGMENT = 32_000_000;

    public static function validateSource(string $source): void
    {
        if ($source === '' || strlen($source) > self::MAX_SOURCE || preg_match('/[\x00-\x20\x7f"\x27<>\\\\?#]/', $source)) {
            throw new RuntimeException('Référence de vidéo HLS invalide.');
        }

        $isMedia = str_starts_with($source, '/medias/');
        if ($isMedia) {
            $relative = substr($source, strlen('/medias/'));
        } elseif (preg_match('/\A[a-z][a-z0-9_-]{0,31}:(.+)\z/D', $source, $match)) {
            $relative = $match[1];
        } else {
            throw new RuntimeException('Référence de vidéo HLS invalide.');
        }

        $segments = self::segments($relative);
        if (count($segments) < ($isMedia ? 3 : 2)
            || !preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,149}\.m3u8\z/D', end($segments))) {
            throw new RuntimeException('La source doit désigner un fichier .m3u8 dans un dossier vidéo.');
        }
    }

    /** Le HTML produit peut être utilisé sans View Vue ; le montage est distinct. */
    public function render(string $source, ?int $spaceId = null): string
    {
        if ($source === '') return '';
        self::validateSource($source);
        $spaceId ??= $this->coreSpaceHelper->getCurrentSpace()->id;
        if ($spaceId < 1 || $this->coreSpaceHelper->getSpace($spaceId) === null) {
            throw new RuntimeException('Space vidéo introuvable.');
        }

        return '<sec_video src="' . HtmlTools::escape($source) . '" space-id="' . $spaceId . '"></sec_video>';
    }

    public function assertSourceInSpace(int $spaceId, string $source): void
    {
        self::validateSource($source);
        $folder = $this->videoFolder($spaceId, $source);
        $playlist = $folder . '/' . $this->playlistName($source);
        if (is_link($playlist) || !is_file($playlist) || !is_readable($playlist)) {
            throw new RuntimeException('Playlist HLS introuvable.');
        }
    }

    /** Préparer le formulaire uniquement après contrôle SPACE_ADMIN par le workflow. */
    public function configurationForEditor(int $spaceId, array $params): array
    {
        $params['video_hls_libraries'] = json_encode(
            (object) $this->libraries($params), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $params['video_hls_password'] = $this->password($params, $spaceId);
        unset($params['video_hls_password_encrypted']);

        return $params;
    }

    /** Vérifier les racines et le mot de passe avant stockage. */
    public function prepareConfiguration(int $spaceId, array $params): array
    {
        $input = (string) ($params['video_hls_libraries'] ?? '{}');
        $libraries = self::parseLibraries($input);
        $password = $params['video_hls_password'] ?? '';
        if (!is_string($password) || strlen($password) > 512 || preg_match('/[\x00-\x1f\x7f]/', $password)) {
            throw new RuntimeException('Mot de passe HLS invalide.');
        }

        // Garder le JSON comme chaîne dans config.params, comme le champ core.code.
        $params['video_hls_libraries'] = json_encode(
            (object) $libraries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        $params['video_hls_password'] = $password;
        unset($params['video_hls_password_encrypted']);

        return $params;
    }

    /** @return array<string,string> */
    public static function parseLibraries(string $input): array
    {
        try { $decoded = json_decode($input, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('Les bibliothèques HLS doivent être un objet JSON.'); }
        if (!is_array($decoded) || !str_starts_with(trim($input), '{') || count($decoded) > 100) {
            throw new RuntimeException('Les bibliothèques HLS doivent être un objet JSON de 100 entrées maximum.');
        }

        foreach ($decoded as $alias => $root) {
            if (!preg_match('/\A[a-z][a-z0-9_-]{0,31}\z/D', (string) $alias) || !is_string($root)
                || strlen($root) > 1024 || !str_starts_with($root, '/') || str_contains($root, "\0")
                || !is_dir($root) || !is_readable($root)) {
                throw new RuntimeException('Alias ou chemin de bibliothèque HLS invalide.');
            }
        }

        return $decoded;
    }

    /** @return array{content:string,mime:string} */
    public function asset(int $spaceId, string $source, string $file): array
    {
        self::validateSource($source);
        if (!preg_match('/\A(?:playlist\.m3u8|video\.key|segment_[0-9]+\.ts)\z/D', $file)) {
            throw new RuntimeException('Fichier HLS non pris en charge.');
        }

        $folder = $this->videoFolder($spaceId, $source);
        if ($file === 'video.key') {
            $key = $this->readKey($folder, $this->configuration($spaceId), $spaceId);

            // Le navigateur reçoit le protocole de l'ancien lecteur, jamais la clé brute.
            return ['content' => SecureVideoKey::encodeForPlayer($key),
                'mime' => 'text/plain; charset=us-ascii'];
        }

        $candidate = $folder . DIRECTORY_SEPARATOR . ($file === 'playlist.m3u8' ? $this->playlistName($source) : $file);
        if (is_link($candidate)) throw new RuntimeException('Fichier HLS indisponible.');
        $path = realpath($candidate);
        if ($path === false || dirname($path) !== $folder || !is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Fichier HLS indisponible.');
        }
        $size = filesize($path);
        $limit = $file === 'playlist.m3u8' ? self::MAX_PLAYLIST : self::MAX_SEGMENT;
        if ($size === false || $size < 1 || $size > $limit) throw new RuntimeException('Fichier HLS trop volumineux ou vide.');
        $content = file_get_contents($path);
        if (!is_string($content) || strlen($content) !== $size) throw new RuntimeException('Lecture HLS impossible.');

        if ($file === 'playlist.m3u8') {
            return ['content' => $this->playlist($content, $spaceId, $source), 'mime' => 'application/vnd.apple.mpegurl; charset=utf-8'];
        }

        return ['content' => $content, 'mime' => 'video/mp2t'];
    }

    /** @return list<string> */
    private static function segments(string $path): array
    {
        $segments = explode('/', $path);
        foreach ($segments as $encoded) {
            $decoded = rawurldecode($encoded);
            if ($encoded === '' || rawurlencode($decoded) !== $encoded || $decoded === '.' || $decoded === '..'
                || str_starts_with($decoded, '.') || str_contains($decoded, '/') || str_contains($decoded, '\\')
                || str_contains($decoded, ':') || preg_match('/[\x00-\x1f\x7f]/', $decoded)) {
                throw new RuntimeException('Chemin HLS invalide.');
            }
        }
        return array_map('rawurldecode', $segments);
    }

    private function playlistName(string $source): string
    {
        $relative = str_starts_with($source, '/medias/')
            ? substr($source, strlen('/medias/')) : explode(':', $source, 2)[1];
        $segments = self::segments($relative);
        return end($segments);
    }

    private function videoFolder(int $spaceId, string $source): string
    {
        $space = $this->coreSpaceHelper->getSpace($spaceId);
        if ($space === null) throw new RuntimeException('Space HLS introuvable.');

        if (str_starts_with($source, '/medias/')) {
            $segments = self::segments(substr($source, strlen('/medias/')));
            if (array_shift($segments) !== $space->medias_dir) throw new RuntimeException('Space HLS invalide.');
            $root = $space->getMediasDir();
        } else {
            [$alias, $relative] = explode(':', $source, 2);
            $segments = self::segments($relative);
            $root = $this->libraries((array) $this->configuration($spaceId))[$alias] ?? null;
            if ($root === null) throw new RuntimeException('Bibliothèque HLS introuvable.');
        }

        array_pop($segments);
        $realRoot = realpath($root);
        if ($realRoot === false || !is_dir($realRoot)) throw new RuntimeException('Bibliothèque HLS indisponible.');

        $candidate = $realRoot;
        foreach ($segments as $segment) {
            $candidate .= DIRECTORY_SEPARATOR . $segment;
            if (is_link($candidate)) throw new RuntimeException('Dossier HLS indisponible.');
        }
        $folder = realpath($candidate);
        if ($folder === false || !str_starts_with($folder . DIRECTORY_SEPARATOR, $realRoot . DIRECTORY_SEPARATOR)
            || !is_dir($folder) || !is_readable($folder)) {
            throw new RuntimeException('Dossier HLS indisponible.');
        }
        return $folder;
    }

    private function playlist(string $content, int $spaceId, string $source): string
    {
        if (!str_starts_with($content, '#EXTM3U') || preg_match('//u', $content) !== 1) {
            throw new RuntimeException('Playlist HLS invalide.');
        }

        $base = '/secure-video/stream?' . http_build_query(['spaceId' => $spaceId, 'source' => $source], '', '&', PHP_QUERY_RFC3986) . '&file=';
        $lines = preg_split('/\r?\n/', $content);
        if ($lines === false || count($lines) > 10000) throw new RuntimeException('Playlist HLS trop longue.');
        $rewritten = [];
        foreach ($lines as $line) {
            if (preg_match('/\Asegment_[0-9]+\.ts\z/D', $line)) {
                $rewritten[] = $base . rawurlencode($line);
            } elseif (str_starts_with($line, '#EXT-X-KEY:') && preg_match('/\A#EXT-X-KEY:METHOD=AES-128,URI="\[URL_OF_KEY\]"(?:,IV=0x[0-9a-fA-F]{32})?\z/D', $line)) {
                $rewritten[] = str_replace('[URL_OF_KEY]', $base . 'video.key', $line);
            } elseif ($line === '' || $line === '#EXTM3U' || preg_match('/\A#EXT(?:INF:[0-9.]+(?:,.*)?|-X-(?:VERSION|TARGETDURATION|MEDIA-SEQUENCE):[0-9]+|-X-(?:PLAYLIST-TYPE):(EVENT|VOD)|-X-(?:ENDLIST|DISCONTINUITY))\z/D', $line)) {
                $rewritten[] = $line;
            } else {
                throw new RuntimeException('Instruction HLS non prise en charge.');
            }
        }
        return implode("\n", $rewritten);
    }

    private function readKey(string $folder, object $params, int $spaceId): string
    {
        $plainPath = $folder . '/video.key';
        $cryptPath = $plainPath . '.crypt';
        if (is_link($plainPath) || is_link($cryptPath)) throw new RuntimeException('Clé HLS indisponible.');
        $password = $this->password((array) $params, $spaceId);

        // Le fichier historique VKEY1 est prioritaire dès qu'il existe.
        if (is_file($cryptPath)) {
            $data = file_get_contents($cryptPath);
            if ($data === false) throw new RuntimeException('Clé HLS indisponible.');
            $key = SecureVideoKey::decrypt($data, $password);
            if (is_file($plainPath)) {
                $plain = file_get_contents($plainPath);
                if (!is_string($plain) || !hash_equals($plain, $key) || !unlink($plainPath)) {
                    throw new RuntimeException('La clé claire ne correspond pas à la clé chiffrée.');
                }
            }
            return $key;
        }

        $plain = is_file($plainPath) ? file_get_contents($plainPath) : false;
        if ($plain === false || strlen($plain) !== 16) throw new RuntimeException('Clé HLS indisponible.');
        if ($password === '') return $plain;

        // Migration lors du premier accès, vérifiée avant suppression de la clé claire.
        $temp = tempnam($folder, '.vkey-');
        if ($temp === false) throw new RuntimeException('Chiffrement de clé impossible.');
        try {
            $encrypted = SecureVideoKey::encrypt($plain, $password);
            if (file_put_contents($temp, $encrypted, LOCK_EX) !== strlen($encrypted)) {
                throw new RuntimeException('Écriture de clé incomplète.');
            }
            if (!is_file($cryptPath) && !rename($temp, $cryptPath)) {
                throw new RuntimeException('Installation de clé impossible.');
            }
        } finally {
            if (is_file($temp)) unlink($temp);
        }
        $stored = file_get_contents($cryptPath);
        if ($stored === false || !hash_equals($plain, SecureVideoKey::decrypt($stored, $password))) {
            throw new RuntimeException('Clé chiffrée différente de la clé originale.');
        }
        if (is_file($plainPath) && !unlink($plainPath)) throw new RuntimeException('Suppression de clé claire impossible.');

        return $plain;
    }

    /** @return array<string,string> */
    private function libraries(array $params): array
    {
        $value = $params['video_hls_libraries'] ?? [];

        // La forme actuelle est une chaîne ; les anciennes configurations peuvent
        // contenir un objet après décodage de la colonne JSON par l'ORM.
        if (is_string($value)) {
            try { $value = json_decode($value, false, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { return []; }
        }
        if ($value instanceof \stdClass) $value = get_object_vars($value);
        if (!is_array($value)) return [];

        // Les chemins restent des chaînes ; videoFolder vérifie leur présence
        // et leur confinement avant toute lecture de fichier.
        return array_filter($value, static fn ($root, $alias) => is_string($alias) && is_string($root), ARRAY_FILTER_USE_BOTH);
    }

    private function password(array $params, int $spaceId): string
    {
        // Le mot de passe est désormais conservé tel quel dans la configuration.
        // Les anciennes valeurs chiffrées restent utilisables avant migration.
        if (array_key_exists('video_hls_password', $params)) {
            return is_string($params['video_hls_password']) ? $params['video_hls_password'] : '';
        }
        $encrypted = $params['video_hls_password_encrypted'] ?? '';
        return is_string($encrypted) && $encrypted !== ''
            ? $this->coreCryptHelper->decrypt($encrypted, 'video:hls:' . $spaceId) : '';
    }

    private function configuration(int $spaceId): object
    {
        $conf = $this->db->selectOrmOneOrNull(Config::class,
            'SELECT * FROM config WHERE extension = % AND space_id = %', ['plg_core', $spaceId]);
        return $conf?->params ?? (object) [];
    }
}
