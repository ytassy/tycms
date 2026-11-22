<?php

namespace YTassy\Tycms\Lib\Scss;


class ScssCompiler
{
    private static ?\Throwable $lastError = null;

    /**
     * $importRoot enables local SCSS imports only from this directory.
     *
     * Imports remain disabled when no root is supplied.
     *
     * @return string|null null if error
     */
    public static function compileScss(string $scss, ?string $importRoot = null): ?string
    {
        self::$lastError = null;

        try {
            $scssCompiler = new \ScssPhp\ScssPhp\Compiler();
            $scssCompiler->setOutputStyle(\ScssPhp\ScssPhp\OutputStyle::COMPRESSED);

            if ($importRoot !== null) {
                $scssCompiler->addImporter(new SafeScssImporter($importRoot));
            }

            return $scssCompiler->compileString($scss)->getCss();
        } catch (\Throwable $ex) {
            self::$lastError = $ex;

            return null;
        }
    }

    public static function getLastError(): ?\Throwable
    {
        return self::$lastError;
    }
}

