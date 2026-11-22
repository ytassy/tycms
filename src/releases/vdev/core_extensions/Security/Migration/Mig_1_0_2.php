<?php

namespace YTassy\Tycms\CoreExts\Security\Migration;

use YTassy\Tycms\Extensions\BaseMigrationVersion;

/** Le WAF reste inactif sur les installations existantes jusqu’à un choix ROOT explicite. */
final class Mig_1_0_2 extends BaseMigrationVersion
{
    public function run(): array
    {
        // Valeurs figées de cette migration : ne pas dépendre des défauts d’une version future du formulaire.
        $patterns = json_encode([
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
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->db->execute(<<<SQL
            UPDATE config SET params = JSON_INSERT(params,
                '$.waf_use_root', JSON_EXTRACT('false', '$'), '$.waf_mode', 'off',
                '$.waf_skip_root', JSON_EXTRACT('true', '$'),
                '$.waf_message', 'Requête refusée par le pare-feu du site.', '$.waf_patterns', %)
            WHERE extension = 'plg_security'
            SQL, [$patterns]);
        return [];
    }
}
