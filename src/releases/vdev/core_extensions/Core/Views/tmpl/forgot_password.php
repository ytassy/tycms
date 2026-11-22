<?php
/** @var string $csrfToken */
/** @var string $loginUrl */
use YTassy\Tycms\Tools\HtmlTools;
?>
<div class="card card-login shadow-sm border-0" style="max-width:600px">
    <div class="card-body p-4 p-sm-5">
        <h1 class="h4 fw-bold mb-3">Mot de passe oublié</h1>
        <p>Indiquez votre adresse e-mail pour recevoir un lien permettant de choisir un nouveau mot de passe.</p>

        <form method="post" action="/forgot-password">
            <input type="hidden" name="csrf_token" value="<?= HtmlTools::escape($csrfToken) ?>">
            <div class="mb-3">
                <label for="email" class="form-label">Adresse e-mail</label>
                <input class="form-control" type="email" id="email" name="email" maxlength="100" required autocomplete="email">
            </div>
            <button class="btn btn-primary w-100" type="submit">Recevoir le lien</button>
        </form>

        <p class="text-center small mt-3 mb-0">
            <a href="<?= HtmlTools::escape($loginUrl) ?>">Retour à la connexion</a>
        </p>
    </div>
</div>
