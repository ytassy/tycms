<?php

namespace YTassy\Tycms\Lib\Scss;

use League\Uri\Contracts\UriInterface;
use League\Uri\Uri;
use ScssPhp\ScssPhp\Importer\Importer;
use ScssPhp\ScssPhp\Importer\ImporterResult;
use ScssPhp\ScssPhp\Syntax;

/**
 * Resolves SCSS modules from one trusted filesystem root.
 *
 * The standard scssphp filesystem importer is a load-path helper, not a
 * sandbox: a relative import containing ".." can escape its load path. This
 * importer only returns real files physically located below $rootPath.
 */
final class SafeScssImporter extends Importer
{
    private readonly string $rootPath;

    private readonly string $rootPrefix;

    public function __construct(string $rootPath)
    {
        $resolvedRoot = realpath($rootPath);
        if ($resolvedRoot === false || !is_dir($resolvedRoot)) {
            throw new \InvalidArgumentException('The SCSS import root must be an existing directory.');
        }

        if (dirname($resolvedRoot) === $resolvedRoot) {
            throw new \InvalidArgumentException('The SCSS import root cannot be the filesystem root.');
        }

        $this->rootPath = rtrim($resolvedRoot, "/\\");
        $this->rootPrefix = $this->rootPath . DIRECTORY_SEPARATOR;
    }

    public function canonicalize(UriInterface $url): ?UriInterface
    {
        $requestedPath = $this->getRequestedPath($url);
        if ($requestedPath === null) {
            return null;
        }

        $resolvedPath = $this->resolveStylesheetPath($requestedPath);
        if ($resolvedPath === null) {
            return null;
        }

        return $this->toFileUri($resolvedPath);
    }

    public function load(UriInterface $url): ?ImporterResult
    {
        $path = $this->fromFileUri($url);
        if ($path === null) {
            return null;
        }

        $resolvedPath = realpath($path);
        if ($resolvedPath === false || !is_file($resolvedPath) || !$this->isAllowedStylesheet($resolvedPath)) {
            return null;
        }

        $contents = file_get_contents($resolvedPath);
        if ($contents === false) {
            throw new \RuntimeException('Unable to read an authorized SCSS import.');
        }

        return new ImporterResult(
            $contents,
            Syntax::forPath($resolvedPath),
            $this->toFileUri($resolvedPath),
        );
    }

    public function __toString(): string
    {
        return 'safe SCSS importer';
    }

    private function getRequestedPath(UriInterface $url): ?string
    {
        if (
            $url->getQuery() !== null
            || $url->getFragment() !== null
            || $url->getUserInfo() !== null
            || $url->getPort() !== null
        ) {
            return null;
        }

        $scheme = $url->getScheme();
        if ($scheme === 'file') {
            $host = $url->getHost();
            if ($host !== null && $host !== '' && strtolower($host) !== 'localhost') {
                return null;
            }

            return $this->fromFileUri($url);
        }

        if ($scheme !== null || $url->getHost() !== null) {
            return null;
        }

        $relativePath = rawurldecode($url->getPath());
        if (!$this->isSafeRelativePath($relativePath)) {
            return null;
        }

        return $this->rootPath . DIRECTORY_SEPARATOR . $relativePath;
    }

    private function resolveStylesheetPath(string $requestedPath): ?string
    {
        if ($this->isFromImport()) {
            $importOnlyPath = $this->withImportOnlySuffix($requestedPath);
            $resolvedImportOnlyPath = $this->findStylesheet($importOnlyPath);
            if ($resolvedImportOnlyPath !== null) {
                return $resolvedImportOnlyPath;
            }
        }

        $resolvedPath = $this->findStylesheet($requestedPath);
        if ($resolvedPath !== null) {
            return $resolvedPath;
        }

        if (pathinfo($requestedPath, PATHINFO_EXTENSION) !== '') {
            return null;
        }

        if ($this->isFromImport()) {
            $resolvedImportOnlyIndex = $this->findStylesheet(
                $requestedPath . DIRECTORY_SEPARATOR . 'index.import',
            );
            if ($resolvedImportOnlyIndex !== null) {
                return $resolvedImportOnlyIndex;
            }
        }

        return $this->findStylesheet($requestedPath . DIRECTORY_SEPARATOR . 'index');
    }

    private function withImportOnlySuffix(string $path): string
    {
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if ($extension === '') {
            return $path . '.import';
        }

        return substr($path, 0, -strlen($extension) - 1) . '.import.' . $extension;
    }

    private function findStylesheet(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if ($extension === 'import') {
            $extension = '';
        }

        if ($extension !== '' && !in_array($extension, ['scss', 'sass'], true)) {
            return null;
        }

        $candidatePaths = [];
        if ($extension === '') {
            foreach (['scss', 'sass'] as $candidateExtension) {
                $candidatePaths[] = $path . '.' . $candidateExtension;
            }
        } else {
            $candidatePaths[] = $path;
        }

        $matches = [];
        foreach ($candidatePaths as $candidatePath) {
            foreach ($this->withPartialVariant($candidatePath) as $candidateVariant) {
                $resolvedPath = realpath($candidateVariant);
                if (
                    $resolvedPath === false
                    || !is_file($resolvedPath)
                    || !$this->isAllowedStylesheet($resolvedPath)
                ) {
                    continue;
                }

                $matches[$resolvedPath] = true;
            }
        }

        if (count($matches) > 1) {
            throw new \RuntimeException('The SCSS import is ambiguous.');
        }

        return array_key_first($matches);
    }

    /** @return list<string> */
    private function withPartialVariant(string $path): array
    {
        $basename = basename($path);
        if (str_starts_with($basename, '_')) {
            return [$path];
        }

        return [dirname($path) . DIRECTORY_SEPARATOR . '_' . $basename, $path];
    }

    private function isAllowedStylesheet(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['scss', 'sass'], true)
            && $this->isInsideRoot($path);
    }

    private function isInsideRoot(string $path): bool
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            return strncasecmp($path, $this->rootPrefix, strlen($this->rootPrefix)) === 0;
        }

        return str_starts_with($path, $this->rootPrefix);
    }

    private function isSafeRelativePath(string $path): bool
    {
        if (
            $path === ''
            || str_contains($path, "\0")
            || str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || preg_match('/^[a-zA-Z]:[\\\\\/]/', $path) === 1
        ) {
            return false;
        }

        return !in_array('..', preg_split('~[\\\\/]~', $path), true);
    }

    private function fromFileUri(UriInterface $url): ?string
    {
        try {
            $uri = $url instanceof Uri ? $url : Uri::new($url);

            return DIRECTORY_SEPARATOR === '\\'
                ? $uri->toWindowsPath()
                : $uri->toUnixPath();
        } catch (\Throwable) {
            return null;
        }
    }

    private function toFileUri(string $path): UriInterface
    {
        return DIRECTORY_SEPARATOR === '\\'
            ? Uri::fromWindowsPath($path)
            : Uri::fromUnixPath($path);
    }
}
