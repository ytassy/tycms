<?php

namespace YTassy\Tycms\CoreExts\Core\Helper\Config;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\Lib\Forms\Form;

class FormsHelper extends BaseHelper
{
    public function getConfigForm(): Form
    {
        $select = static fn (string $name, string $label, string $default, array $values): array => [
            'name' => $name, 'type' => 'core.select', 'label' => $label, 'default' => $default,
            'options' => array_map(static fn (string $value): array => ['value' => $value, 'label' => $value], $values),
        ];

        // Centraliser ici les sections du formulaire du plugin Core.
        return new Form($this, ['id' => 'plugin.core.config', 'version' => 1, 'sections' => [
            ['id' => 'mermaid', 'label' => 'Shortcodes : diagrammes Mermaid', 'fields' => [
                ['type' => 'core.note', 'description' => 'Syntaxe : [[DIAGRAM,width=600px,theme=forest]] puis le code Mermaid, puis [[/DIAGRAM]]. Les options du diagramme remplacent les valeurs par défaut.'],
                ['name' => 'mermaid_default_width', 'type' => 'core.text', 'label' => 'Largeur', 'default' => '95%', 'validation' => ['pattern' => '^((?:10000|[1-9][0-9]{0,3})px|100%|[1-9][0-9]?%)$']],
                $select('mermaid_default_align', 'Alignement', 'center', ['left', 'center', 'right']),
                $select('mermaid_default_look', 'Rendu natif', 'auto', ['auto', 'classic', 'handDrawn', 'neo']),
                $select('mermaid_default_theme', 'Thème', 'auto', ['auto', 'default', 'base', 'dark', 'forest', 'neutral', 'neo', 'neo-dark', 'redux', 'redux-dark', 'redux-color', 'redux-dark-color']),
                $select('mermaid_default_sketch', 'Effet croquis (distinct du rendu handDrawn)', '1', ['0', '1']),
                ['name' => 'mermaid_default_wrapwidth', 'type' => 'core.number', 'label' => 'Retour à la ligne flowchart (px)', 'default' => 120, 'validation' => ['type' => 'integer', 'minimum' => 40, 'maximum' => 1000]],
            ]],
            ['id' => 'hls', 'label' => 'Vidéos HLS sécurisées', 'fields' => [
                ['type' => 'core.note', 'description' => 'Sans mot de passe, video.key reste en clair. Dans les médias publics, toute personne connaissant son URL peut la télécharger. Changer le mot de passe rend les fichiers .crypt existants illisibles avant rechiffrement.'],
                ['name' => 'video_hls_libraries', 'type' => 'core.code', 'syntax' => 'json', 'label' => 'Bibliothèques HLS (JSON)', 'default' => '{}', 'validation' => ['maxLength' => 120000],
                    'description' => 'Objet {"alias":"/chemin/absolu"}. Racines lisibles par PHP, y compris hors du site.'],
                ['name' => 'video_hls_password', 'type' => 'core.text', 'label' => 'Mot de passe des clés vidéo (facultatif)', 'default' => '', 'ui' => ['inputType' => 'password'], 'validation' => ['maxLength' => 512],
                    'description' => 'Vide : clés en clair acceptées. Renseignez-le pour lire les fichiers VKEY1 et chiffrer les clés claires au premier accès. Conservez-le pour pouvoir relire les vidéos. Ce mot de passe est stocké en clair dans la configuration du Space.'],
            ]],
            ['id' => 'bunny', 'label' => 'Shortcodes : vidéos Bunny', 'fields' => [
                ['type' => 'core.note', 'description' => 'Syntaxe : [[VIDEO, bunny:ID_BIBLIOTHEQUE/ID_VIDEO]]. USER_name, USER_email et USER_id utilisent le visiteur connecté ; sans connexion, ils affichent vide.'],
                ['name' => 'video_bunny_height', 'type' => 'core.text', 'label' => 'Hauteur du lecteur', 'default' => '500px', 'validation' => ['pattern' => '^[1-9][0-9]{0,3}(px|vh|%)$']],
                ['name' => 'video_bunny_expire', 'type' => 'core.number', 'label' => 'Validité des liens signés (secondes)', 'default' => 7200, 'validation' => ['type' => 'integer', 'minimum' => 900, 'maximum' => 604800]],
                ['name' => 'video_bunny_tokens', 'type' => 'core.code', 'syntax' => 'json', 'label' => 'Clés Bunny (JSON)', 'default' => '', 'validation' => ['maxLength' => 120000],
                    'description' => 'Objet {"bibliothèque":"clé"}. Vide conserve les clés enregistrées ; {} les supprime. Les clés sont stockées en clair en base et réaffichées uniquement dans cette configuration autorisée.'],
            ]],
        ]]);
    }
}
