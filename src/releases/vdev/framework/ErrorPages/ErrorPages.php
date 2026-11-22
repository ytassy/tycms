<?php

namespace YTassy\Tycms\ErrorPages;

use Configuration;
use Throwable;
use YTassy\Tycms\Tools\HtmlTools;

class ErrorPages
{
    

    public static function printErrorPageAndExit(
        string $title,
        string $message,
        int $code,
        Configuration $conf,
        ?Throwable $exception = null,
        bool $displayCode = true,
        bool $displayHomeButton = true,
        bool $displayTitle = true,
        bool $escapeMessageInHtml = true
        ){
        http_response_code($code);

        $title = HtmlTools::escape($title);
        $message = $escapeMessageInHtml ? ("<p>" . HtmlTools::escape($message) . "</p>"): $message;

        $htmlException = '';

        if ($exception != null && $conf->debug) $htmlException = self::getExceptionHtml($exception);

?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $title ?></title>
    <link rel="stylesheet" href="<?= ASSETS_CORE_PATH ?>/tpl_main/fa/css/fontawesome.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CORE_PATH ?>/tpl_main/fa/css/solid.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CORE_PATH ?>/tpl_main/fa/css/regular.min.css">
    <link rel="stylesheet" href="<?= ASSETS_CORE_PATH ?>/tpl_main/fa/css/brands.min.css">
    <script src="<?= ASSETS_CORE_PATH ?>/tpl_main/js/bootstrap.bundle.min.js"></script>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            background-color: #0f172a;
            color: #f8fafc;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            text-align: center;
            max-width: 1500px;
            background: #1e293b;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3);
            border: 1px solid #334155;
        }
        .code {
            font-size: 80px;
            font-weight: 800;
            line-height: 1;
            color: #f43f5e;
            margin-bottom: 10px;
            letter-spacing: -2px;
        }
        h1 {
            font-size: 22px;
            font-weight: 600;
            margin-bottom: 12px;
            color: #f1f5f9;
        }
        p {
            color: #94a3b8;
            font-size: 15px;
            line-height: 1.5;
            margin-bottom: 28px;
        }
        .btn {
            display: inline-block;
            background-color: #3b82f6;
            color: #ffffff;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: 500;
            font-size: 14px;
            transition: background-color 0.2s ease;
        }
        .btn:hover {
            background-color: #2563eb;
        }


        .exception-wrapper {
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            color: #f8fafc;
            max-width: 2000px;
            margin: 20px auto;
            display: flex;
            flex-direction: column;
            gap: 24px;
            line-height: 1.5;
            text-align: left;
        }
        .exception-card {
            background-color: #1e293b;
            border: 1px solid #334155;
            border-radius: 12px;
            padding: 24px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.3);
        }
        .exception-header {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 12px;
        }
        .exception-badge {
            background-color: #f43f5e;
            color: #ffffff;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .exception-badge-previous {
            background-color: #eab308;
            color: #0f172a;
        }
        .exception-class {
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            color: #94a3b8;
            font-size: 14px;
        }
        .exception-message {
            font-size: 20px;
            font-weight: 600;
            color: #f1f5f9;
            margin-bottom: 12px;
            word-break: break-word;
        }
        .exception-location {
            font-size: 14px;
            color: #38bdf8;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            margin-bottom: 20px;
        }
        .exception-section-title {
            font-size: 14px;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 12px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .exception-trace-list {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        .exception-trace-item {
            background-color: #0f172a;
            border: 1px solid #1e293b;
            border-radius: 8px;
            padding: 12px 16px;
            font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
            font-size: 13px;
        }
        .exception-trace-head {
            display: flex;
            justify-content: space-between;
            color: #f8fafc;
            margin-bottom: 4px;
        }
        .exception-trace-call {
            color: #38bdf8;
            font-weight: 600;
        }
        .exception-trace-file {
            color: #64748b;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <?php if ($displayCode) { ?><div class="code"><?= $code ?></div><?php } ?>
        <?php if ($displayTitle) { ?><h1><?= $title ?></h1><?php } ?>
        <?= $message ?>
        <?= $htmlException ?>
        <?php if ($displayHomeButton) { ?><a href="/" class="btn">Retourner à l'accueil</a><?php } ?>
    </div>
</body>
</html>

<?php
        exit();
    }


    private static function getExceptionHtml(Throwable $e): string
    {
        ob_start();
        ?>
        <div class="exception-wrapper">
            <?php 
            $current = $e;
            $depth = 0;
            while ($current !== null): 
            ?>
                <div class="exception-card">
                    <div class="exception-header">
                        <span class="exception-badge <?= $depth > 0 ? 'exception-badge-previous' : '' ?>">
                            <?= $depth === 0 ? 'Exception' : 'Causé par' ?>
                        </span>
                        <span class="exception-class"><?= HtmlTools::escape(get_class($current)) ?></span>
                    </div>

                    <div class="exception-message">
                        <?= HtmlTools::escape($current->getMessage()) ?> 
                        <span style="color: #64748b; font-weight: 400;">(#<?= $current->getCode() ?>)</span>
                    </div>

                    <div class="exception-location">
                        <?= HtmlTools::escape($current->getFile()) ?>:<b><?= $current->getLine() ?></b>
                    </div>

                    <div class="exception-section-title">Stack Trace</div>
                    <div class="exception-trace-list">
                        <?php 
                        $trace = $current->getTrace();
                        $total = count($trace);
                        foreach ($trace as $index => $step): 
                        ?>
                            <div class="exception-trace-item">
                                <div class="exception-trace-head">
                                    <span class="exception-trace-call">
                                        #<?= $total - $index ?> 
                                        <?= HtmlTools::escape(($step['class'] ?? '') . ($step['type'] ?? '') . ($step['function'] ?? '')) ?>()
                                    </span>
                                </div>
                                <?php if (isset($step['file'])): ?>
                                    <div class="exception-trace-file">
                                        <?= HtmlTools::escape($step['file']) ?>:<?= $step['line'] ?? '?' ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php 
                $current = $current->getPrevious();
                $depth++;
            endwhile; 
            ?>
        </div>
        <?php
        return ob_get_clean();
    }

}
