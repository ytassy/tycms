<?php

return new class extends \Adminer\Plugin {
    public function head($dark = null)
    {
        if (!isset($_GET['edit'])) {
            return null;
        }

        // Les fichiers sont copiés dans l’image ; conserver la CSP d’Adminer 6.
        echo '<link rel="stylesheet" href="codemirror/codemirror.css">';
        echo \Adminer\script_src('codemirror/codemirror.js', true);
        echo \Adminer\script_src('codemirror/javascript.js', true);
        echo \Adminer\script(<<<'JS'
document.addEventListener('DOMContentLoaded', function () {
    if (!window.CodeMirror) return;

    document.querySelectorAll('textarea[data-json-editor]').forEach(function (textarea) {
        var editor = CodeMirror.fromTextArea(textarea, {
            mode: 'application/json',
            lineNumbers: true,
            lineWrapping: true,
            indentUnit: 4,
            // Afficher toutes les lignes pour adapter la hauteur au contenu.
            viewportMargin: Infinity
        });

        // Garder le champ natif à jour, y compris pour la sauvegarde AJAX d’Adminer.
        editor.on('change', function () {
            editor.save();
            textarea.dispatchEvent(new Event('input', { bubbles: true }));
        });

        // Réutiliser les raccourcis d’enregistrement du formulaire natif.
        editor.getInputField().jushTextarea = textarea;
        setupSubmitHighlightInput(editor.getInputField());
    });
});
JS);

        return null;
    }

    public function editInput($table, $field, $attrs, $value)
    {
        // Laisser les autres types et les valeurs NULL au rendu natif d’Adminer.
        if (!isset($_GET['edit']) || !in_array(strtolower($field['type']), ['text', 'mediumtext', 'longtext'], true)
            || !is_string($value)) {
            return null;
        }

        // Réutiliser le décodeur d’Adminer pour préserver la précision des grands nombres.
        $decoded = \Adminer\json_decode_exact($value);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return null;
        }

        $value = \Adminer\json_encode_exact($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return '<textarea data-json-editor cols="50" rows="12"' . $attrs . '>'
            . "\n" . \Adminer\h($value) . '</textarea>';
    }
};
