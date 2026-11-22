<?php

use YTassy\Tycms\CoreExts\Core\Models\Space;
use YTassy\Tycms\Tools\HtmlTools;

/** @var array<Space> $spaces */


?>

<h1 class="text-center">Où souhaite-vous vous rendre ?</h1>

<div class="d-flex flex-wrap justify-content-center gap-4 mt-4">
    <?php foreach ($spaces as $space): ?>
        <?php
        $params = $space->params;
        $icon = is_object($params) ? ($params->icon ?? '') : ($params['icon'] ?? '');
        ?>
        <div class="card shadow-sm text-center" style="width: 300px; min-height: 300px;">
            <div class="card-body d-flex flex-column justify-content-center p-4">
                <?php if ($icon !== ''): ?>
                    <i class="<?= HtmlTools::escape((string) $icon) ?> fa-3x mb-4" aria-hidden="true"></i>
                <?php endif; ?>
                <h2 class="h4 mb-4"><?= HtmlTools::escape($space->title) ?></h2>
                <form method="post" action="" class="mt-auto">
                    <input type="hidden" name="id" value="<?= $space->id ?>">
                    <input type="hidden" name="csrf_token" value="<?= HtmlTools::escape($csrfToken) ?>">
                    <button type="submit" class="btn btn-primary w-100">Choisir</button>
                </form>
            </div>
        </div>
    <?php endforeach; ?>
</div>
