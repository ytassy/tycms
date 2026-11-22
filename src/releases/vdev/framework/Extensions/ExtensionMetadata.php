<?php

declare(strict_types=1);

namespace YTassy\Tycms\Extensions;

use RuntimeException;

/** Contrat de version partagé par l’extension installée et son constructeur de paquets. */
final class ExtensionMetadata
{
    public const MAX_BYTES = 65536;

    public static function read(string $file, ?string $expectedPath = null): array
    {
        if (!is_file($file)) throw new RuntimeException('Fichier de version introuvable : ' . $file);
        if (is_link($file)) throw new RuntimeException('Lien symbolique interdit pour le fichier de version : ' . $file);
        if (!is_readable($file) || filesize($file) > self::MAX_BYTES) throw new RuntimeException('Fichier de version illisible ou supérieur à 64 Kio : ' . $file);
        $json = @file_get_contents($file);
        if ($json === false) throw new RuntimeException('Lecture impossible : ' . $file);
        try { $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException $error) { throw new RuntimeException('JSON de version invalide dans ' . $file . ' : ' . $error->getMessage(), 0, $error); }
        if (!is_array($data)) throw new RuntimeException('Objet JSON de version attendu dans ' . $file);
        try { $metadata = self::validate($data); }
        catch (RuntimeException $error) { throw new RuntimeException($file . ' : ' . $error->getMessage(), 0, $error); }
        if ($expectedPath !== null && self::relativePath($metadata) !== $expectedPath) {
            throw new RuntimeException('Le type et le dossier de ' . $file . ' ne correspondent pas à l’extension sélectionnée : ' . $expectedPath);
        }
        return $metadata;
    }

    public static function validate(array $data): array
    {
        $keys = ['schema_version', 'id', 'type', 'folder', 'version', 'source_versions',
            'cms_min', 'cms_max_exclusive', 'php_min', 'php_max_exclusive'];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) throw new RuntimeException('Champ requis absent de version.json : ' . $key);
        }
        if (count($data) !== count($keys)) throw new RuntimeException('Champ inconnu dans version.json : utiliser uniquement les champs documentés.');
        if ($data['schema_version'] !== 1) throw new RuntimeException('schema_version doit valoir 1.');

        if (!is_string($data['type'])) throw new RuntimeException('Type d’extension invalide.');
        $prefix = ['plugin' => 'plg', 'module' => 'mod', 'template' => 'tpl'][$data['type']] ?? null;
        if ($prefix === null || !is_string($data['folder']) || !preg_match('/\A[A-Z][A-Za-z0-9]{0,63}\z/', $data['folder'])) {
            throw new RuntimeException('Type ou nom de dossier d’extension invalide.');
        }
        $id = $prefix . '_' . strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $data['folder']));
        if ($data['id'] !== $id || in_array($id, [
            'plg_core', 'plg_security', 'plg_tools', 'plg_matrix',
            'plg_ty_catalog', 'plg_automation', 'plg_test', 'tpl_main',
        ], true)) {
            throw new RuntimeException('Identifiant d’extension incohérent ou réservé au système.');
        }
        self::version($data['version']);
        foreach (['cms', 'php'] as $runtime) {
            $minimum = self::version($data[$runtime . '_min']);
            $maximum = self::version($data[$runtime . '_max_exclusive']);
            if (!version_compare($minimum, $maximum, '<')) throw new RuntimeException('Bornes de compatibilité ' . strtoupper($runtime) . ' incohérentes.');
        }
        $sources = $data['source_versions'];
        // La première installation n'est jamais restreinte par ce champ. « * »
        // autorise toute mise à jour depuis une version antérieure ; une liste
        // vide interdit les mises à jour.
        if ($sources !== '*' && (!is_array($sources) || !array_is_list($sources) || count($sources) > 256)) {
            throw new RuntimeException('Liste de versions sources ou marqueur « * » attendu.');
        }
        if ($sources !== '*') {
            foreach ($sources as $source) {
                self::version($source);
                if (!version_compare($source, $data['version'], '<')) throw new RuntimeException('Une version source doit précéder la cible.');
            }
            if (count($sources) !== count(array_unique($sources))) throw new RuntimeException('Version source dupliquée.');
        }

        // Un ordre stable produit les mêmes métadonnées sur le disque et dans les signatures.
        return array_replace(array_fill_keys($keys, null), $data);
    }

    public static function version(mixed $value): string
    {
        if (!is_string($value) || strlen($value) > 48 || !preg_match('/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/', $value)) {
            throw new RuntimeException('Version stable X.Y.Z attendue.');
        }
        return $value;
    }

    public static function relativePath(array $metadata): string
    {
        return ['plugin' => 'plugins', 'module' => 'modules', 'template' => 'templates'][$metadata['type']] . '/' . $metadata['folder'];
    }

    public static function artifactName(array $metadata): string
    {
        return $metadata['id'] . '-' . str_replace('.', '_', $metadata['version']);
    }
}
