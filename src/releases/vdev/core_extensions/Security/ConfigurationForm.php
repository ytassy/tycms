<?php

namespace YTassy\Tycms\CoreExts\Security;

use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\Lib\Forms\Form;

/** Formulaire ROOT : les explications font partie du contrat présenté aux administrateurs. */
final class ConfigurationForm
{
    public static function build(BaseApplicationItem $owner): Form
    {
        // Répéter les règles sous chaque liste pour qu’elles restent visibles pendant la saisie.
        $origins = static fn (string $name, string $label, string $description, string $example): array => [
            'name' => $name, 'type' => 'core.textarea', 'label' => $label, 'default' => '',
            'validation' => ['maxLength' => 16000], 'ui' => ['rows' => 6, 'placeholder' => $example],
            'description' => $description . ' Format : UNE origine HTTPS par LIGNE (touche Entrée). '
                . 'Ne séparez pas les valeurs par des espaces, virgules ou points-virgules. '
                . 'Indiquez https:// puis le nom exact, sans chemin ni joker. '
                . 'https://example.org n’autorise ni https://www.example.org ni https://sub.example.org : ajoutez chaque origine sur sa propre ligne. '
                . 'Un port facultatif est accepté : https://media.example.org:8443. '
                . 'Refusés : example.org, http://example.org, https://*.example.org, https://example.org/page. '
                . 'Les lignes vides sont ignorées et les doublons retirés. Maximum 100 origines par liste et 6000 octets pour l’ensemble des listes.',
        ];

        return new Form($owner, ['id' => 'plugin.security.config', 'version' => 3, 'sections' => [
            ['id' => 'iframes', 'label' => 'Iframes', 'fields' => [
                ['type' => 'core.note', 'description' => 'Ces réglages s’appliquent au Space choisi, à ses pages publiques et aux aperçus. Ils contrôlent les sites affichés dans vos pages, pas les sites pouvant intégrer vos pages. Les permissions d’isolation (sandbox) restent distinctes.'],
                ['name' => 'iframe_mode', 'type' => 'core.select', 'label' => 'Sites autorisés dans les iframes', 'default' => 'all_https', 'options' => [
                    ['value' => 'all_https', 'label' => 'Tous les domaines HTTPS'],
                    ['value' => 'allowlist', 'label' => 'Origine du site et liste autorisée'],
                    ['value' => 'self', 'label' => 'Origine du site uniquement'],
                ], 'description' => '« Tous les domaines HTTPS » autorise tout site HTTPS. « Origine du site et liste autorisée » autorise le site actuel et les origines listées ci-dessous. « Origine du site uniquement » ignore la liste externe. Une restriction s’applique aussi aux lecteurs vidéo intégrés et peut masquer des contenus existants.'],
                $origins('iframe_origins', 'Origines autorisées des iframes',
                    'Liste utilisée uniquement en mode « liste autorisée ». Vide : seule l’origine du site est admise. Exemple complet : ligne 1 = https://docs.google.com (Google Slides), ligne 2 = https://www.youtube.com (YouTube), ligne 3 = https://player.vimeo.com (Vimeo), ligne 4 = https://player.mediadelivery.net (Bunny). Le service distant doit lui-même autoriser son intégration.',
                    "https://docs.google.com\nhttps://www.youtube.com\nhttps://player.vimeo.com\nhttps://player.mediadelivery.net"),
            ]],
            ['id' => 'resources', 'label' => 'Images et médias externes', 'fields' => [
                ['type' => 'core.note', 'description' => 'Les ressources du site restent autorisées. Une liste vide interdit les sources externes pour la catégorie concernée. Cette autorisation ne remplace pas les validations des composants, des fichiers ni les règles du service distant.'],
                $origins('image_origins', 'Origines des images externes',
                    'Exemple : ligne 1 = https://images.example.org, ligne 2 = https://cdn.example.org. Cela autorise leurs images, sans changer la validation des URL dans les composants. Les images internes à Google Slides dépendent de la politique de Google, pas de cette liste.',
                    "https://images.example.org\nhttps://cdn.example.org"),
                $origins('media_origins', 'Origines des fichiers audio et vidéo externes',
                    'Exemple : ligne 1 = https://audio.example.org, ligne 2 = https://video.example.org, ligne 3 = https://media.example.org:8443. Indiquez les origines, pas les URL complètes des fichiers MP3 ou MP4. Les lecteurs YouTube et Vimeo utilisent les réglages Iframes.',
                    "https://audio.example.org\nhttps://video.example.org\nhttps://media.example.org:8443"),
            ]],
            ['id' => 'appearance', 'label' => 'Styles et polices externes', 'fields' => [
                ['type' => 'core.note', 'description' => 'Ces autorisations permettent notamment aux feuilles de style personnalisées de charger des ressources externes. N’autorisez que des services de confiance. Elles ne permettent pas d’exécuter des scripts externes.'],
                $origins('style_origins', 'Origines des feuilles de style externes',
                    'Exemple : ligne 1 = https://fonts.googleapis.com (feuilles Google Fonts), ligne 2 = https://styles.example.org. Pour charger Roboto depuis votre SCSS : @import url("https://fonts.googleapis.com/css2?family=Roboto"); Ajoutez aussi https://fonts.gstatic.com dans la liste des fichiers de polices ci-dessous.',
                    "https://fonts.googleapis.com\nhttps://styles.example.org"),
                $origins('font_origins', 'Origines des fichiers de polices externes',
                    'Exemple : ligne 1 = https://fonts.gstatic.com (fichiers Google Fonts), ligne 2 = https://fonts.example.org. Pour Google Fonts, il faut AUSSI https://fonts.googleapis.com dans la liste des feuilles de style au-dessus : ce sont deux catégories différentes.',
                    "https://fonts.gstatic.com\nhttps://fonts.example.org"),
            ]],
            ...\YTassy\Tycms\CoreExts\Security\Waf\WafConfigurationForm::sections(),
        ]]);
    }
}
