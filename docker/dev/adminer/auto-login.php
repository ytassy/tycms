<?php

return new class extends \Adminer\Plugin {
    public function loginFormField($name, $heading, $value)
    {
        // Conserver le formulaire standard, avec son jeton de session Adminer 6.
        if (!filter_var(getenv('DB_ADMIN_AUTO_LOGIN'), FILTER_VALIDATE_BOOLEAN)) {
            return null;
        }

        // Amorcer seulement une ouverture sans utilisateur ni action en cours.
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET' || isset($_GET['username']) || !empty($_POST)) {
            return null;
        }

        if ($name === 'username' || $name === 'password') {
            $credential = $name === 'username' ? 'root' : getenv('DB_ROOT_PASSWORD');
            $escaped = htmlspecialchars((string) $credential, ENT_QUOTES, 'UTF-8');

            return $heading . '<input name="auth[' . $name . ']" type="'
                . ($name === 'password' ? 'password' : 'text') . '" value="' . $escaped . '">';
        }

        // Soumettre le vrai formulaire : cookies et jeton CSRF restent gérés par Adminer.
        if ($name === 'db') {
            return $heading . $value . \Adminer\script(
                "document.addEventListener('DOMContentLoaded', function () { document.querySelector('input[name=\"auth[password]\"]').form.requestSubmit(); });"
            );
        }

        return null;
    }
};
