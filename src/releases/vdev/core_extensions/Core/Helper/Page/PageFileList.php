<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Page;

use RuntimeException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Acl\Permissions;
use YTassy\Tycms\CoreExts\Core\Helper\TraitCoreAdditionalHelpers;
use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tools\HtmlTools;

/** Lecture des fichiers publics d’un dossier et présentation commune aux aperçus. */
class PageFileList extends BaseHelper
{
    use TraitCoreAdditionalHelpers;

    public static function validate(object $props): void
    {
        $folder = $props->folder ?? null;
        if ($folder !== null && (!is_string($folder) || strlen($folder) > 1024 || preg_match('/[\\\\\x00-\x1f\x7f]/', $folder)
            || ($folder !== '' && array_filter(explode('/', $folder), static fn ($part) => $part === '' || str_starts_with($part, '.')) !== []))) {
            ExceptionResponse::throwWarning('Dossier de fichiers invalide. Utilisez un chemin relatif aux médias de l’espace.');
        }
        foreach (['compact', 'show_date', 'show_size', 'show_thumbnails', 'show_open', 'show_download'] as $field) {
            if (property_exists($props, $field) && !is_bool($props->{$field})) ExceptionResponse::throwWarning('Option de liste de fichiers invalide : ' . $field . '.');
        }
        for ($i = 1; $i <= 2; $i++) {
            $sort = $props->{'sort_' . $i} ?? 'name';
            $direction = $props->{'direction_' . $i} ?? 'asc';
            $first = $props->{'first_' . $i} ?? '';
            if (!in_array($sort, ['name', 'extension', 'modified', 'size'], true) || !in_array($direction, ['asc', 'desc'], true)
                || !is_string($first) || mb_strlen($first) > 500 || preg_match('/[\x00-\x1f\x7f]/', $first)) {
                ExceptionResponse::throwWarning('Critère de tri de fichiers invalide.');
            }
        }
    }

    public function validateForEditor(object $props, int $spaceId): void
    {
        self::validate($props);
        if (!isset($props->folder)) return;

        // Publier le contenu d’un dossier exige de pouvoir consulter les médias de cet espace.
        if (!$this->coreAclHelper->hasGlobalPermission(Permissions::MEDIA_READ, $spaceId)) {
            ExceptionResponse::throwWarning('La lecture des médias est requise pour choisir un dossier de fichiers.');
        }
        $space = $this->coreSpaceHelper->getAllSpacesByIds()[$spaceId] ?? null;
        if ($space === null) ExceptionResponse::throwWarning('Espace média introuvable.');
        try {
            $this->coreMediaHelper->assertDirectory($space, $props->folder);
        } catch (RuntimeException) {
            ExceptionResponse::throwWarning('Le dossier de fichiers est introuvable dans cet espace.');
        }
    }

    public function files(Space $space, object $props): array
    {
        self::validate($props);
        if (!isset($props->folder)) return [];

        // Pas d’arborescence complète ni de récursion sur les sous-dossiers.
        $listing = $this->coreMediaHelper->listDirectory($space, $props->folder, false, 1000);
        $files = array_values(array_filter($listing['entries'], static fn ($entry) => $entry['type'] === 'file'));
        $files = self::sortFiles($files, $props);
        return $files;
    }

    public static function sortFiles(array $files, object $props): array
    {
        self::validate($props);
        $rules = [];
        for ($i = 1; $i <= 2; $i++) {
            $rules[] = [$props->{'sort_' . $i} ?? 'name', ($props->{'direction_' . $i} ?? 'asc') === 'desc' ? -1 : 1,
                array_values(array_filter(array_map('trim', explode(',', mb_strtolower($props->{'first_' . $i} ?? ''))), static fn ($value) => $value !== ''))];
        }
        $rank = static function (string $value, array $priorities): int {
            foreach ($priorities as $index => $token) {
                if (str_contains(mb_strtolower($value), $token)) return $index;
            }
            return count($priorities);
        };
        usort($files, static function ($left, $right) use ($rules, $rank): int {
            foreach ($rules as [$field, $direction, $priorities]) {
                $priority = $rank((string) $left[$field], $priorities) <=> $rank((string) $right[$field], $priorities);
                if ($priority !== 0) return $priority;
                $order = in_array($field, ['size', 'modified'], true)
                    ? $left[$field] <=> $right[$field] : strnatcasecmp(mb_strtolower($left[$field]), mb_strtolower($right[$field]));
                if ($order !== 0) return $order * $direction;
            }
            return strnatcasecmp(mb_strtolower($left['name']), mb_strtolower($right['name'])) ?: strcmp($left['name'], $right['name']);
        });
        return $files;
    }

    public function render(int $spaceId, object $props, string $locale): string
    {
        if (!isset($props->folder)) return '';
        $space = $this->coreSpaceHelper->getAllSpacesByIds()[$spaceId] ?? null;
        if ($space === null) return '';
        try {
            $files = $this->files($space, $props);
        } catch (RuntimeException) {
            // Un dossier retiré après publication ne doit pas casser toute la page.
            return '';
        }
        return self::renderFiles($files, $props, $locale);
    }

    public static function renderFiles(array $files, object $props, string $locale): string
    {
        self::validate($props);
        if ($files === []) return '';
        $compact = $props->compact ?? false;
        $english = str_starts_with($locale, 'en');
        $html = '<ul class="page-file-list' . ($compact ? ' page-file-list--compact' : '') . '">';
        foreach ($files as $file) {
            $name = HtmlTools::escape($file['name']);
            $html .= '<li class="page-file-list-entry"><div class="page-file-list-main"><strong class="page-file-list-name">' . $name . '</strong><span class="page-file-list-actions">';
            foreach (['open' => ['url', 'fa-eye', $english ? 'Open' : 'Ouvrir'],
                'download' => ['url', 'fa-download', $english ? 'Download' : 'Télécharger']] as $action => [$url, $icon, $label]) {
                if (($props->{'show_' . $action} ?? true) !== true) continue;
                $html .= '<a href="' . HtmlTools::escape($file[$url]) . '"' . ($action === 'open' ? ' target="_blank" rel="noopener noreferrer"' : ' download="' . $name . '"')
                    . ' title="' . $label . ' : ' . $name . '" aria-label="' . $label . ' : ' . $name . '"><i class="fa-solid ' . $icon . '" aria-hidden="true"></i>'
                    . ($compact ? '' : ' ' . $label) . '</a>';
            }
            $html .= '</span></div><div class="page-file-list-meta">';
            if (($props->show_thumbnails ?? false) && str_starts_with($file['mime'] ?? '', 'image/')) {
                $html .= '<img class="page-file-list-thumbnail" src="' . HtmlTools::escape($file['url']) . '" alt="" loading="lazy" width="40" height="40">';
            }
            if ($props->show_size ?? true) $html .= '<span>' . self::formatSize($file['size']) . '</span>';
            if ($props->show_date ?? true) {
                $html .= '<time datetime="' . date('c', $file['modified']) . '">' . self::formatDate($file['modified'], $compact, $locale) . '</time>';
            }
            $html .= '</div></li>';
        }
        return $html . '</ul>';
    }

    public static function formatDate(int $timestamp, bool $compact, string $locale): string
    {
        $months = str_starts_with($locale, 'en')
            ? ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec']
            : ['Jan', 'Fév', 'Mar', 'Avr', 'Mai', 'Juin', 'Juil', 'Août', 'Sep', 'Oct', 'Nov', 'Déc'];
        return date('d', $timestamp) . ' ' . $months[(int) date('n', $timestamp) - 1] . ' '
            . date($compact ? 'y' : 'Y', $timestamp) . ($compact ? '' : ' - ' . date('H:i', $timestamp));
    }

    public static function formatSize(int $size): string
    {
        $units = ['o', 'Ko', 'Mo', 'Go', 'To'];
        $index = 0;
        $value = $size;
        while ($value >= 1024 && $index < count($units) - 1) {
            $value /= 1024;
            $index++;
        }
        return number_format($value, $index === 0 ? 0 : 2, '.', ' ') . ' ' . $units[$index];
    }
}
