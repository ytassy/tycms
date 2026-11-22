<?php

namespace YTassy\Tycms\CoreExts\Security\Waf;

use JsonException;
use RuntimeException;

/** Contrat des réglages WAF, indépendant des origines CSP et des objets HTTP. */
final class WafConfiguration
{
    public const MAX_PATTERNS = 50;
    public const MAX_JSON_BYTES = 32768;

    public static function examplePatterns(): string
    {
        return json_encode(self::patternDefinition(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** Jeu de départ volontairement ciblé : les signatures trop générales restent à la charge du ROOT. */
    private static function patternDefinition(): array
    {
        return [
            'strings' => [
                '<?php', '<?=', 'php://filter', 'php://input', 'expect://',
                'data://', 'phar://', 'zip://', 'glob://', 'file://',
                '../', '..\\', '%2e%2e%2f', '%2e%2e%5c', '%00',
                'union select', 'or 1=1', '<script', 'javascript:',
                'onerror=', 'onload=', 'unserialize(', 'file_put_contents(',
                'move_uploaded_file(', 'base64_decode(', 'shell_exec(',
                'passthru(', 'proc_open(', 'call_user_func(',
                '/etc/passwd', 'win.ini', 'boot.ini', 'information_schema',
                'pg_catalog', 'into outfile', 'xp_cmdshell',
            ],
            'regexes' => [
                // Regrouper les variantes proches limite le nombre de comparaisons PCRE par valeur.
                'php_code' => '~<\?(?:php|=)|(?:eval|assert|system|exec|shell_exec|passthru|proc_open|popen|pcntl_exec|base64_decode|gzinflate|gzuncompress|str_rot13|create_function|include|include_once|require|require_once|fopen|fwrite|readfile|file_get_contents|unlink|copy|chmod|chown|symlink|curl_exec|curl_multi_exec|fsockopen|pfsockopen|stream_socket_client)\s*\(|\$[a-zA-Z_]\w*\s*\(|\$\$[a-zA-Z_]\w*|call_user_func(?:_array)?\s*\(~i',
                'php_wrapper' => '~(?:php|expect|data|phar|zip|glob|file)://~i',
                'path_traversal' => '~(?:\.\.[/\\\\]|%2e%2e(?:%2f|%5c)|%00)~i',
                'php_superglobal' => '~\$_(?:GET|POST|REQUEST|COOKIE|SERVER|FILES)\b~i',
                'xss_probe' => '~(?:<\s*script\b|javascript\s*:|\b(?:onerror|onload|onclick|onmouseover|onfocus|onblur|onsubmit|onchange|onkeydown|onkeyup|onkeypress|ontoggle)\s*=)~i',
                'serialized_object' => '~O:\d+:"[^"]+":\d+:~',
                'sql_probe' => '~(?:union\s+(?:all\s+)?select|(?:or|and)\s+[\'"]?1[\'"]?\s*=\s*[\'"]?1|(?:sleep|benchmark)\s*\(\s*\d+|information_schema|pg_catalog|into\s+outfile|load_file\s*\(|xp_cmdshell)~i',
                'file_inclusion' => '~(?:/etc/passwd|/proc/self/environ|win\.ini|boot\.ini)~i',
                'template_injection' => '~\{\{[\s\S]{1,200}?\}\}|\{%[\s\S]{1,200}?%\}~',
            ],
        ];
    }

    public static function defaults(): array
    {
        return ['waf_use_root' => false, 'waf_mode' => 'off', 'waf_skip_root' => true,
            'waf_message' => 'Requête refusée par le pare-feu du site.', 'waf_patterns' => self::examplePatterns()];
    }

    public static function normalize(array $values): array
    {
        if (array_diff_key($values, self::defaults()) !== [] || array_diff_key(self::defaults(), $values) !== []) {
            throw new RuntimeException('Les paramètres WAF sont incomplets ou inconnus.');
        }
        if (!is_bool($values['waf_use_root']) || !is_bool($values['waf_skip_root'])
            || !in_array($values['waf_mode'], ['off', 'observe', 'block'], true)) {
            throw new RuntimeException('Le mode ou les options du WAF sont invalides.');
        }
        $message = $values['waf_message'];
        if (!is_string($message) || !mb_check_encoding($message, 'UTF-8') || trim($message) === '' || mb_strlen($message) > 500
            || preg_match('/[\x00-\x1f\x7f]/', $message)) {
            throw new RuntimeException('Le message WAF doit contenir entre 1 et 500 caractères sur une seule ligne.');
        }
        $values['waf_message'] = trim($message);
        $patterns = self::patterns($values['waf_patterns']);
        $values['waf_patterns'] = json_encode($patterns,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return $values;
    }

    public static function patterns(mixed $json): object
    {
        if (!is_string($json) || strlen($json) > self::MAX_JSON_BYTES) {
            throw new RuntimeException('Les motifs WAF sont limités à 32768 octets de JSON.');
        }
        try { $data = json_decode($json, false, 8, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new RuntimeException('JSON WAF invalide : vérifiez les guillemets et les antislashs doublés des regex.'); }

        if (!$data instanceof \stdClass || array_keys(get_object_vars($data)) === []
            || array_diff(array_keys(get_object_vars($data)), ['strings', 'regexes']) !== []
            || !isset($data->strings, $data->regexes) || !is_array($data->strings) || !array_is_list($data->strings)
            || !$data->regexes instanceof \stdClass
            || count($data->strings) + count(get_object_vars($data->regexes)) > self::MAX_PATTERNS) {
            throw new RuntimeException('Les motifs WAF attendent {"strings":[],"regexes":{}} avec au maximum 50 motifs.');
        }
        foreach ($data->strings as $literal) {
            if (!is_string($literal) || trim($literal) === '' || strlen($literal) > 256 || str_contains($literal, "\0")) {
                throw new RuntimeException('Une chaîne WAF doit contenir entre 1 et 256 octets, sans caractère nul.');
            }
        }
        foreach (get_object_vars($data->regexes) as $name => $regex) {
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,59}$/D', (string) $name)
                || !is_string($regex) || strlen($regex) > 1024 || str_contains($regex, "\0")
                || BoundedRegex::match($regex, '') === false) {
                throw new RuntimeException('Une regex WAF est invalide : nom ASCII unique de 1 à 60 caractères et expression PCRE de 1024 octets maximum.');
            }
        }
        return $data;
    }
}
