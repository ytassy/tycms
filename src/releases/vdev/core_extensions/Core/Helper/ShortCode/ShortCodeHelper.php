<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\ShortCode;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\Models\Config;
use YTassy\Tycms\Tools\HtmlTools;

class ShortCodeHelper extends BaseHelper
{
    private array $configurations = [];

    /** Le HTML éditorial doit être purifié avant cet appel ; le résultat ne se stocke pas. */
    public function applyShortCodes(string $html, ?int $spaceId = null): string
    {
        if (!str_contains($html, '[[')) return $html;
        if (strlen($html) > 500000) throw new RuntimeException('Contenu de shortcodes trop volumineux.');

        $spaceId ??= $this->coreSpaceHelper->getCurrentSpace()->id;
        $params = $this->configuration($spaceId);
        $protected = [];
        $prefix = 'tf-shortcode-' . bin2hex(random_bytes(12)) . '-';
        $literal = $prefix . 'literal';
        $protect = static function (string $value) use (&$protected, $prefix): string {
            $key = $prefix . count($protected);
            $protected[$key] = $value;
            return $key;
        };

        // Masquer les attributs et les zones techniques avant tout remplacement de texte.
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>', LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) return $html;
        $this->protectLiterals($body, $literal);
        $html = '';
        foreach ($body->childNodes as $node) $html .= $dom->saveHTML($node);

        // Les sources Mermaid sont opaques : USER et VIDEO n'y sont jamais interprétés.
        $count = 0;
        $html = DiagramShortCode::replace($html, $params, static function (string $diagram) use ($protect, &$count): string {
            return ++$count <= 50 ? $protect($diagram) : $protect('<p>Nombre maximal de diagrammes dépassé.</p>');
        });
        $user = null;
        $loadedUser = false;
        $html = preg_replace_callback('~\[\[(USER_(?:name|email|id)|VIDEO\s*,\s*bunny:[^\[\]]+)\]\]~i',
            function (array $match) use ($params, $spaceId, &$user, &$loadedUser): string {
                if (str_starts_with(strtoupper($match[1]), 'USER_')) {
                    if (!$loadedUser) {
                        $user = $this->coreUserHelper->getUser();
                        $loadedUser = true;
                    }
                    if ($user === null || $user->id < 1) return '';
                    $value = match (strtolower(substr($match[1], 5))) {
                        'name' => trim(trim($user->first_name) . ' ' . trim($user->last_name)),
                        'email' => $user->email,
                        'id' => (string) $user->id,
                    };
                    return HtmlTools::escape($value);
                }
                return $this->video($match[0], $params, $spaceId);
            }, $html);

        return str_replace($literal, '[[', strtr($html, $protected));
    }

    private function protectLiterals(DOMNode $node, string $literal, bool $skip = false): void
    {
        if ($node instanceof DOMElement) {
            $skip = $skip || in_array(strtolower($node->tagName),
                ['script', 'style', 'pre', 'code', 'textarea', 'template', 'svg', 'math', 'iframe'], true);
            foreach ($node->attributes as $attribute) {
                $attribute->value = str_replace('[[', $literal, $attribute->value);
            }
        } elseif ($skip || $node->nodeType === XML_COMMENT_NODE) {
            $node->nodeValue = str_replace('[[', $literal, $node->nodeValue ?? '');
        }
        foreach ($node->childNodes as $child) $this->protectLiterals($child, $literal, $skip);
    }

    public function configuration(int $spaceId): object
    {
        if (!isset($this->configurations[$spaceId])) {
            $conf = $this->db->selectOrmOneOrNull(Config::class,
                'SELECT * FROM config WHERE extension = % AND space_id = %', ['plg_core', $spaceId]);
            $this->configurations[$spaceId] = $conf?->params ?? (object) [];
        }
        return $this->configurations[$spaceId];
    }

    /** Le WF doit vérifier le droit d'édition du Space avant d'appeler cette méthode. */
    public function configurationForEditor(int $spaceId, array $params): array
    {
        $tokens = $this->bunnyTokens($params, $spaceId);
        unset($params['video_bunny_tokens_encrypted']);
        $params['video_bunny_tokens'] = json_encode((object) $tokens,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $params;
    }

    public function prepareConfiguration(int $spaceId, array $params): array
    {
        $input = trim((string) ($params['video_bunny_tokens'] ?? ''));
        // Un champ vide conserve la configuration ; une sauvegarde migre les
        // anciennes valeurs chiffrées vers le JSON simple demandé.
        $tokens = $input === '' ? $this->bunnyTokens((array) $this->configuration($spaceId), $spaceId)
            : self::parseBunnyTokens($input);
        $params['video_bunny_tokens'] = json_encode((object) $tokens, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        unset($params['video_bunny_tokens_encrypted']);
        unset($this->configurations[$spaceId]);
        return $params;
    }

    /** @return array<string,string> */
    private static function parseBunnyTokens(string $input): array
    {
        try { $tokens = json_decode($input, true, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('Les clés Bunny doivent être un objet JSON.'); }
        if (!is_array($tokens) || !str_starts_with(trim($input), '{') || count($tokens) > 100) {
            throw new RuntimeException('Les clés Bunny doivent être un objet JSON de 100 bibliothèques maximum.');
        }
        foreach ($tokens as $id => $token) {
            if (!preg_match('/^[1-9][0-9]{0,14}$/D', (string) $id) || !is_string($token)
                || trim($token) === '' || strlen($token) > 1024) {
                throw new RuntimeException('Identifiant ou clé Bunny invalide.');
            }
        }
        return $tokens;
    }

    /** @return array<string,string> */
    private function bunnyTokens(array $params, int $spaceId): array
    {
        $plain = $params['video_bunny_tokens'] ?? null;
        if (is_string($plain) && $plain !== '') return self::parseBunnyTokens($plain);

        // Les anciens secrets restent lisibles jusqu'à la prochaine sauvegarde.
        $encrypted = $params['video_bunny_tokens_encrypted'] ?? '';
        return is_string($encrypted) && $encrypted !== ''
            ? self::parseBunnyTokens($this->coreCryptHelper->decrypt($encrypted, 'shortcodes:bunny:' . $spaceId)) : [];
    }

    private function video(string $shortcode, object $params, int $spaceId): string
    {
        if (!preg_match('~^\[\[VIDEO\s*,\s*bunny:([1-9][0-9]{0,14})/([a-z0-9-]{1,100})\s*\]\]$~iD', $shortcode, $match)) return $shortcode;
        $height = (string) ($params->video_bunny_height ?? '500px');
        if (!preg_match('/^[1-9][0-9]{0,3}(?:px|vh|%)$/D', $height)) $height = '500px';
        $url = $this->bunnyUrl($match[1], $match[2], $spaceId);
        return '<iframe title="Lecteur vidéo Bunny" src="' . HtmlTools::escape($url)
            . '" style="width:100%;height:' . $height . '" loading="lazy" allowfullscreen'
            . ' allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen"></iframe>';
    }
    /** Générer un lien lecteur sans transmettre la clé de bibliothèque au navigateur. */
    public function bunnyUrl(string $libraryId, string $videoId, int $spaceId): string
    {
        if (!preg_match('/^[1-9][0-9]{0,14}$/D', $libraryId) || !preg_match('/^[a-z0-9-]{1,100}$/iD', $videoId)) {
            throw new RuntimeException('Identifiants Bunny invalides.');
        }
        $params = $this->configuration($spaceId);
        $url = 'https://player.mediadelivery.net/embed/' . $libraryId . '/' . $videoId . '?autoplay=false&preload=false';
        $tokens = $this->bunnyTokens((array) $params, $spaceId);
        if (isset($tokens[$libraryId])) {
            $expires = time() + max(900, min(604800, (int) ($params->video_bunny_expire ?? 7200)));
            $url .= '&token=' . hash('sha256', $tokens[$libraryId] . $videoId . $expires) . '&expires=' . $expires;
        }
        return $url;
    }

}
