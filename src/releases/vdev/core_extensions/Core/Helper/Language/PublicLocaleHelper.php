<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Language;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Request\Request;

final class PublicLocaleHelper extends BaseHelper
{
    private const SESSION_KEY = 'public_locale';

    private ?string $locale = null;
    private bool $hasPrefix = false;

    public function prepare(Request $request, int $spaceId): void
    {
        $languages = $this->coreSpaceLanguageHelper->listForSpace($spaceId);
        $configured = [];
        $active = [];
        $default = null;

        foreach ($languages as $language) {
            $configured[$language->locale] = true;

            if ($language->enabled) {
                $active[$language->locale] = true;
            }

            if ($language->is_default) {
                $default = $language->locale;
            }
        }

        if ($default === null) {
            $default = $this->coreSpaceLanguageHelper->getDefaultLocale($spaceId);
        }

        // Seules les langues configurées réservent un préfixe : un slug comme /cgu
        // doit continuer à désigner une page, même s'il ressemble à une locale.
        $firstSegment = explode('/', trim($request->path, '/'), 2)[0];
        $this->hasPrefix = isset($configured[strtolower($firstSegment)]);

        if ($this->hasPrefix) {
            $this->locale = isset($active[strtolower($firstSegment)])
                ? strtolower($firstSegment)
                : null;
            return;
        }

        // La dernière langue d'une page publique prime sur une nouvelle préférence du navigateur.
        $remembered = $this->session->get(self::SESSION_KEY);
        if (is_string($remembered) && isset($active[$remembered])) {
            $this->locale = $remembered;
            return;
        }

        $header = $request->header('Accept-Language', '');
        $this->locale = $this->negotiate(is_string($header) ? $header : '', $active) ?? $default;
    }

    public function rememberPublicRoute(): void
    {
        // Une route résolue valide le préfixe ; une URL publique inconnue ne change pas la session.
        if (!$this->hasPrefix || $this->locale === null) {
            return;
        }

        if ($this->session->get(self::SESSION_KEY) !== $this->locale) {
            $this->session->set(self::SESSION_KEY, $this->locale);
        }
    }

    public function locale(): ?string
    {
        return $this->locale;
    }

    public function hasPrefix(): bool
    {
        return $this->hasPrefix;
    }

    public function publicPath(string $path): ?string
    {
        if ($this->locale === null) {
            return null;
        }

        return '/' . $this->locale . ($path === '/' ? '/' : $path);
    }

    /** @param array<string, true> $active */
    private function negotiate(string $header, array $active): ?string
    {
        $preferences = [];

        foreach (array_slice(explode(',', substr($header, 0, 4096)), 0, 30) as $position => $part) {
            $parts = array_map('trim', explode(';', $part));
            $tag = strtolower($parts[0]);

            if (preg_match('/^[a-z]{2,3}(?:-[a-z0-9]{2,8})*$/', $tag) !== 1) {
                continue;
            }

            $quality = 1.0;
            if (isset($parts[1])) {
                if (preg_match('/^q=(0(?:\.[0-9]{0,3})?|1(?:\.0{0,3})?)$/i', $parts[1], $match) !== 1) {
                    continue;
                }

                $quality = (float) $match[1];
            }

            if ($quality > 0) {
                $preferences[] = ['tag' => $tag, 'quality' => $quality, 'position' => $position];
            }
        }

        usort($preferences, static fn (array $a, array $b): int =>
            ($b['quality'] <=> $a['quality']) ?: ($a['position'] <=> $b['position']));

        foreach ($preferences as $preference) {
            $tag = $preference['tag'];

            if (isset($active[$tag])) {
                return $tag;
            }

            $primary = explode('-', $tag, 2)[0];
            if (isset($active[$primary])) {
                return $primary;
            }
        }

        return null;
    }
}
