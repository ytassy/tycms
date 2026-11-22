<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Fonts;

use JsonException;
use RuntimeException;

/** Construit un catalogue d'installation à partir des seules sources officielles. */
final class FontCatalog
{
    public const METADATA_URL = 'https://fonts.google.com/metadata/fonts';
    public const TREE_URL = 'https://api.github.com/repos/google/fonts/git/trees/main?recursive=1';
    private const LICENSES = ['OFL.txt', 'LICENSE.txt', 'LICENCE.txt', 'NOTICE', 'NOTICE.txt'];
    private const CATEGORIES = ['Sans Serif', 'Serif', 'Monospace', 'Display', 'Handwriting'];

    public static function decode(string $json): array
    {
        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Le catalogue reçu n’est pas un document JSON valide.', previous: $exception);
        }
        if (!is_array($data)) {
            throw new RuntimeException('Le format du catalogue est invalide.');
        }
        return $data;
    }

    public static function fromGoogle(string $metadataJson, string $treeJson): array
    {
        $metadata = self::decode($metadataJson);
        $tree = self::decode($treeJson);
        if (!self::isRevision($tree['sha'] ?? null) || ($tree['truncated'] ?? null) !== false
            || !is_array($tree['tree'] ?? null) || !is_array($metadata['familyMetadataList'] ?? null)) {
            throw new RuntimeException('Les sources du catalogue sont incomplètes ou leur format a changé.');
        }

        // Associer les licences à la révision Git immuable, sans accepter d'URL dans les données.
        $packages = [];
        foreach ($tree['tree'] as $entry) {
            if (!is_array($entry) || !is_string($entry['path'] ?? null)) {
                throw new RuntimeException('L’arbre des licences est invalide.');
            }
            $parts = explode('/', $entry['path']);
            if (count($parts) !== 3 || !in_array($parts[0], ['ofl', 'apache', 'ufl'], true)
                || !in_array($parts[2], self::LICENSES, true) || !preg_match('/\A[a-z0-9]{1,90}\z/', $parts[1])) {
                continue;
            }
            if (($entry['type'] ?? null) !== 'blob' || !self::isRevision($entry['sha'] ?? null)) {
                throw new RuntimeException('Une empreinte de licence est invalide.');
            }
            $path = $parts[0] . '/' . $parts[1];
            if (isset($packages[$parts[1]]) && $packages[$parts[1]]['path'] !== $path) {
                throw new RuntimeException('Plusieurs sources de licence correspondent à la même famille.');
            }
            $packages[$parts[1]]['path'] = $path;
            $packages[$parts[1]]['licenses'][$parts[2]] = ['name' => $parts[2], 'sha' => $entry['sha']];
        }
        unset($tree['tree']);

        $families = [];
        foreach ($metadata['familyMetadataList'] as $family) {
            if (!is_array($family)) {
                throw new RuntimeException('Une entrée du catalogue est invalide.');
            }
            if (($family['isOpenSource'] ?? false) !== true) {
                continue;
            }
            if (!is_string($family['family'] ?? null) || !is_array($family['fonts'] ?? null)) {
                throw new RuntimeException('Les métadonnées d’une famille sont incomplètes.');
            }
            $name = $family['family'];
            $slug = preg_replace('/[^a-z0-9]/', '', strtolower($name));
            $package = $packages[$slug] ?? null;
            if ($package === null || !self::isFamilyName($name)
                || !in_array($family['category'] ?? null, self::CATEGORIES, true)
                || !array_intersect(array_keys($package['licenses']), ['OFL.txt', 'LICENSE.txt', 'LICENCE.txt'])) {
                continue;
            }
            $variants = array_values(array_filter(array_map('strval', array_keys($family['fonts'])), self::isVariant(...)));
            if ($variants === []) {
                continue;
            }
            sort($variants);
            ksort($package['licenses']);
            $id = 'google_' . $slug;
            if (isset($families[$id])) {
                throw new RuntimeException('Deux familles partagent le même identifiant.');
            }
            $families[$id] = [
                'family' => $name, 'category' => $family['category'], 'variants' => $variants,
                'path' => $package['path'], 'licenses' => array_values($package['licenses']),
            ];
        }
        ksort($families);
        $catalog = ['revision' => $tree['sha'], 'families' => $families];
        self::validate($catalog);
        return $catalog;
    }

    /** Vérifier aussi le fichier persistant avant de l'utiliser comme source de confiance. */
    public static function validate(array $catalog): void
    {
        if (!self::isRevision($catalog['revision'] ?? null) || !is_array($catalog['families'] ?? null)
            || count($catalog['families']) < 1 || count($catalog['families']) > 10000) {
            throw new RuntimeException('Le catalogue est vide ou son format est invalide.');
        }
        foreach ($catalog['families'] as $id => $family) {
            if (!is_string($id) || !preg_match('/\Agoogle_([a-z0-9]{1,90})\z/', $id, $slug)
                || !is_array($family) || !self::isFamilyName($family['family'] ?? null)
                || !in_array($family['category'] ?? null, self::CATEGORIES, true)
                || !is_string($family['path'] ?? null)
                || !preg_match('~\A(?:ofl|apache|ufl)/' . $slug[1] . '\z~', $family['path'])
                || (isset($family['revision']) && !self::isRevision($family['revision']))
                || !is_array($family['variants'] ?? null) || count($family['variants']) < 1 || count($family['variants']) > 18
                || !is_array($family['licenses'] ?? null) || count($family['licenses']) < 1 || count($family['licenses']) > 5) {
                throw new RuntimeException('Une famille du catalogue ne respecte pas le format autorisé.');
            }
            foreach ($family['variants'] as $variant) {
                if (!self::isVariant($variant)) {
                    throw new RuntimeException('Une variante de police est invalide.');
                }
            }
            $names = [];
            foreach ($family['licenses'] as $license) {
                if (!is_array($license) || !in_array($license['name'] ?? null, self::LICENSES, true)
                    || !self::isRevision($license['sha'] ?? null) || in_array($license['name'], $names, true)) {
                    throw new RuntimeException('Une licence du catalogue est invalide.');
                }
                $names[] = $license['name'];
            }
            if (!array_intersect($names, ['OFL.txt', 'LICENSE.txt', 'LICENCE.txt'])) {
                throw new RuntimeException('Une famille ne possède pas de licence complète.');
            }
        }
    }

    private static function isRevision(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{40}\z/', $value) === 1;
    }

    private static function isFamilyName(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 200 && preg_match("~\\A[A-Za-z0-9 &'().+/-]+\\z~", $value) === 1;
    }

    private static function isVariant(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[1-9]00i?\z/', $value) === 1;
    }
}
