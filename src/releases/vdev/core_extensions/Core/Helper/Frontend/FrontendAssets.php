<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Frontend;

use ReflectionClass;
use RuntimeException;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Tools\HtmlTools;

/** Composer les assets des extensions chargées sans inclure leur frontend dans le build Core. */
final class FrontendAssets extends BaseHelper
{
    private ?string $nonce = null;

    public function cspNonce(): ?string { return $this->nonce; }

    /** Les variables locales ne peuvent activer aucun serveur Vite en production. */
    public function developmentSettings(): array
    {
        if (!$this->in_dev) return ['core' => false, 'extension' => '', 'port' => 3001];

        $core = strtolower(getenv('DEV_CORE_VITE') ?: 'true');
        $extension = getenv('DEV_EXTENSION') ?: '';
        $port = getenv('DEV_EXTENSION_VITE_PORT') ?: '3001';
        if (!in_array($core, ['true', 'false'], true)
            || ($extension !== '' && !preg_match('~^(plugins|modules|templates)/[A-Z][A-Za-z0-9]*$~D', $extension))
            || !ctype_digit($port) || (int) $port < 1024 || (int) $port > 65535 || (int) $port === 3000) {
            throw new RuntimeException('Configuration des serveurs frontend DEV invalide.');
        }

        return ['core' => $core === 'true', 'extension' => $extension, 'port' => (int) $port];
    }

    /** Origines exactes, communes aux scripts, au HMR et aux ressources frontend. */
    public function developmentOrigins(): array
    {
        $settings = $this->developmentSettings();
        $origins = $settings['core'] ? ['http://localhost:3000'] : [];
        if ($settings['extension'] !== '') $origins[] = 'http://localhost:' . $settings['port'];
        return $origins;
    }

    /** Le nonce est créé seulement pour les réponses qui rendent effectivement l'Import Map. */
    public function renderTags(): string
    {
        $settings = $this->developmentSettings();
        $coreBase = ASSETS_CORE_PATH . '/plg_core/';
        $runtime = $coreBase . 'runtime/' . ($this->in_dev ? 'dev/' : 'prod/');
        $imports = [];
        foreach (['vue', 'pinia', 'vue-i18n', 'vue-router'] as $name) $imports[$name] = $runtime . $name . '.js';

        $clients = [];
        $styles = [];
        if ($settings['core']) {
            $imports['@tycms/api'] = 'http://localhost:3000' . $coreBase . 'extension-api.js';
            $main = 'http://localhost:3000' . $coreBase . 'main.js';
            $clients[] = 'http://localhost:3000' . $coreBase . '@vite/client';
        } else {
            $manifest = new FrontendManifest(APP_ROOT . '/public' . $coreBase);
            $main = $manifest->entry('main.js', $coreBase, $styles);
            $imports['@tycms/api'] = $manifest->entry('extension-api.js', $coreBase, $styles);
        }

        // Vite conserve ces URL externes en DEV ; le navigateur les résout par la même map.
        foreach ($imports as $name => $url) $imports['https://esm.tycms.invalid/' . $name] = $url;

        $extensions = [];
        $selectedFound = $settings['extension'] === '';
        foreach ($this->app->getExtensions() as $id => $extension) {
            $entry = $extension->getFrontendEntry();
            if ($entry === null) continue;
            if (!preg_match('~^(plg|mod|tpl)_[a-z][a-z0-9_]*$~D', $id)
                || !preg_match('~^src/(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+\.js$~D', $entry)) {
                throw new RuntimeException('Déclaration frontend d’extension invalide.');
            }

            $directory = dirname((new ReflectionClass($extension))->getFileName());
            $root = realpath(APP_ROOT . '/extensions');
            if ($root === false || !str_starts_with($directory, $root . DIRECTORY_SEPARATOR)) {
                throw new RuntimeException('Le frontend doit appartenir à une extension installée.');
            }
            $relative = substr($directory, strlen($root) + 1);
            $base = '/assets/' . $id . '/';
            $moduleName = '@extensions/' . $id;
            if ($settings['extension'] === $relative) {
                $selectedFound = true;
                $frontend = realpath($directory . '/frontend');
                $source = realpath($directory . '/frontend/' . $entry);
                if ($frontend === false || $source === false || !str_starts_with($frontend, $directory . '/')
                    || !str_starts_with($source, $frontend . '/')) {
                    throw new RuntimeException('Sources frontend de l’extension DEV introuvables ou hors du dossier autorisé.');
                }
                $origin = 'http://localhost:' . $settings['port'];
                $imports[$moduleName] = $origin . $base . $entry;
                $clients[] = $origin . $base . '@vite/client';
            } else {
                $assetDirectory = APP_ROOT . '/public' . $base;
                $imports[$moduleName] = (new FrontendManifest(rtrim($assetDirectory, '/')))->entry($entry, $base, $styles, true);
            }
            $extensions[] = $moduleName;
        }
        if (!$selectedFound) throw new RuntimeException('L’extension DEV sélectionnée n’est pas chargée ou ne déclare aucun frontend.');

        // Un JSON hexadécimal empêche toute fermeture de balise depuis une métadonnée.
        $flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        $this->nonce ??= base64_encode(random_bytes(24));
        $html = '<script type="importmap" nonce="' . $this->nonce . '">' . json_encode(['imports' => $imports], $flags) . '</script>' . "\n";
        $html .= '<script id="tycms-extensions" type="application/json">' . json_encode($extensions, $flags) . '</script>' . "\n";
        foreach (array_unique($styles) as $url) $html .= '<link rel="stylesheet" href="' . HtmlTools::escape($url) . '">' . "\n";
        foreach ($clients as $url) $html .= '<script type="module" src="' . HtmlTools::escape($url) . '"></script>' . "\n";
        return $html . '<script type="module" src="' . HtmlTools::escape($main) . '"></script>';
    }
}
