<?php
/** @var string $csrfToken */
/** @var string $loginUrl */
/** @var string $formAction */
/** @var bool $valid */
use YTassy\Tycms\Tools\HtmlTools;
?>
<div class="card card-login shadow-sm border-0" style="max-width:600px">
    <div class="card-body p-4 p-sm-5">
        <h1 class="h4 fw-bold mb-3">Choisir un nouveau mot de passe</h1>

        <?php if (!$valid): ?>
            <p>Ce lien est invalide ou expiré, ou la récupération n’est plus autorisée pour ce compte.</p>
            <a href="/forgot-password" class="btn btn-primary w-100">Faire une nouvelle demande</a>
        <?php else: ?>
            <form method="post" action="<?= HtmlTools::escape($formAction) ?>">
                <input type="hidden" name="csrf_token" value="<?= HtmlTools::escape($csrfToken) ?>">
                <div class="mb-3">
                    <label for="password" class="form-label">Nouveau mot de passe</label>
                    <input class="form-control" type="password" id="password" name="password" minlength="12" maxlength="72" required autocomplete="new-password">
                    <div class="form-text">12 caractères minimum, 72 octets maximum.</div>
                </div>
                <div class="mb-3">
                    <label for="password_confirmation" class="form-label">Confirmer le mot de passe</label>
                    <input class="form-control" type="password" id="password_confirmation" name="password_confirmation" minlength="12" maxlength="72" required autocomplete="new-password">
                </div>
                <button class="btn btn-primary w-100" type="submit">Enregistrer le mot de passe</button>
            </form>
        <?php endif; ?>

        <p class="text-center small mt-3 mb-0">
            <a href="<?= HtmlTools::escape($loginUrl) ?>">Retour à la connexion</a>
        </p>
    </div>
</div>
