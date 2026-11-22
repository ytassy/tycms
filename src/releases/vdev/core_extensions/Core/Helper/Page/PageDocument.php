<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Page;

use YTassy\Tycms\Response\ExceptionResponse;

/** Structure JSON v3, indépendante des composants et de leurs formulaires. */
final class PageDocument
{
    public const VERSION = 3;
    public const MAX_DEPTH = 12;
    public const MAX_NODES = 500;
    public const MAX_ZONE_BODIES = 100;

    public static function childZones(object $item): array
    {
        $zones = [];
        foreach (get_object_vars($item->zones ?? new \stdClass()) as $bodies) {
            foreach ($bodies as $body) $zones[] = $body;
        }
        return $zones;
    }

    /** Retrouve un nœud par identifiant dans un document ou une liste d'items. */
    public static function findNode(mixed $documentOrItems, string $nodeId): ?object
    {
        $items = is_object($documentOrItems) ? ($documentOrItems->items ?? null) : $documentOrItems;

        if (!is_array($items)) {
            return null;
        }

        foreach ($items as $item) {
            if (!is_object($item)) {
                continue;
            }

            if (($item->id ?? null) === $nodeId) {
                return $item;
            }

            foreach (self::childZones($item) as $zone) {
                $found = self::findNode($zone->items ?? [], $nodeId);

                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    public static function validateCommonVisibility(object $props): void
    {
        $common = $props->common ?? null;
        if ($common !== null && !is_object($common)) self::invalid('Options communes invalides.');
        if (is_object($common) && property_exists($common, 'visible') && !is_bool($common->visible)) {
            self::invalid('L’option Afficher/Masquer doit être un booléen.');
        }
    }

    public static function normalize(mixed $input): object
    {
        if (!is_object($input) || ($input->version ?? null) !== self::VERSION || !is_array($input->items ?? null)
            || !array_is_list($input->items)) self::invalid('Document de page v3 attendu.');
        if (!is_object($input->container ?? null) || !is_bool($input->container->fluid ?? null)) {
            self::invalid('Conteneur de page invalide.');
        }
        PageSize::assertDocument($input);

        $count = 0;
        $ids = [];
        return (object) [
            'version' => self::VERSION,
            'container' => (object) ['fluid' => $input->container->fluid],
            'items' => self::items($input->items, $count, $ids, 0),
        ];
    }

    public static function normalizeForRender(mixed $input): object
    {
        return self::normalize($input);
    }

    private static function items(array $items, int &$count, array &$ids, int $depth): array
    {
        if ($depth > self::MAX_DEPTH) self::invalid('La page contient trop de niveaux.');
        $result = [];
        foreach ($items as $item) {
            if (!is_object($item) || !is_string($item->type ?? null)
                || preg_match('/^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_-]*$/D', $item->type) !== 1) self::invalid('Type de composant invalide.');
            if ($depth === 0 && $item->type !== 'core.row') self::invalid('La racine de la page doit contenir des lignes.');
            $id = self::id($item, $count, $ids);
            if (!is_object($item->props ?? null)) self::invalid('Données de composant invalides.');
            $props = self::copy($item->props);
            self::validateCommonVisibility($props);
            PageCommonStyles::validate($props);
            $zones = new \stdClass();
            if (!is_object($item->zones ?? null)) self::invalid('Zones de composant invalides.');
            if (count(get_object_vars($item->zones)) > 12) self::invalid('Trop de zones de contenu.');

            foreach (get_object_vars($item->zones) as $name => $bodies) {
                if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) !== 1 || !is_array($bodies)
                    || !array_is_list($bodies) || count($bodies) > self::MAX_ZONE_BODIES) self::invalid('Zone de contenu invalide.');
                $zones->{$name} = [];
                foreach ($bodies as $body) {
                    if (!is_object($body) || !is_object($body->props ?? null) || !is_array($body->items ?? null)
                        || !array_is_list($body->items)) self::invalid('Corps de zone invalide.');
                    $bodyId = self::id($body, $count, $ids);
                    $bodyProps = self::copy($body->props);
                    self::validateCommonVisibility($bodyProps);
                    PageCommonStyles::validate($bodyProps);
                    $icon = $body->icon ?? '';
                    if (!is_string($icon) || ($icon !== '' && preg_match('/^fa-(?:solid|regular|brands) fa-[a-z0-9-]{1,80}$/D', $icon) !== 1)) self::invalid('Icône de section invalide.');
                    $zones->{$name}[] = (object) [
                        'id' => $bodyId,
                        'label' => PageLocalizedText::normalize($body->label ?? '', 500),
                        'icon' => $icon,
                        'sizes' => self::sizes($body->sizes ?? new \stdClass()),
                        'props' => $bodyProps,
                        'items' => self::items($body->items, $count, $ids, $depth + 1),
                    ];
                }
            }
            $result[] = (object) ['id' => $id, 'type' => $item->type, 'props' => $props, 'zones' => $zones];
        }
        return $result;
    }

    private static function id(object $node, int &$count, array &$ids): string
    {
        if (++$count > self::MAX_NODES) self::invalid('La page contient trop de composants.');
        $id = $node->id ?? null;
        if (!is_string($id) || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $id) !== 1 || isset($ids[$id])) {
            self::invalid('Identifiant de composant invalide ou en double.');
        }
        $ids[$id] = true;
        return $id;
    }

    private static function sizes(mixed $value): object
    {
        if (!is_object($value)) self::invalid('Largeurs de zone invalides.');
        foreach (get_object_vars($value) as $key => $size) {
            if (!in_array($key, ['xs', 'sm', 'md', 'lg', 'xl', 'xxl'], true) || !is_int($size) || $size < 0 || $size > 12) {
                self::invalid('Largeur de zone invalide.');
            }
        }
        return clone $value;
    }

    private static function copy(object $value): object
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 512, JSON_THROW_ON_ERROR);
    }

    private static function invalid(string $message): never
    {
        ExceptionResponse::throwWarning($message);
    }
}
