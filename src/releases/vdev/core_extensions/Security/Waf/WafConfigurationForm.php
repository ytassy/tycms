<?php

namespace YTassy\Tycms\CoreExts\Security\Waf;

/** Sections isolées du formulaire WAF : exemples JSON et API développeur restent près des options. */
final class WafConfigurationForm
{
    public static function sections(): array
    {
        $groups = [
            ['id' => 'waf_inheritance', 'label' => 'WAF : héritage', 'fields' => [
                ['type' => 'core.note', 'description' => 'L’héritage concerne UNIQUEMENT le WAF : les domaines et la CSP restent propres au Space. Les valeurs locales du WAF sont conservées et redeviennent actives si vous décochez cette option.'],
                ['name' => 'waf_use_root', 'type' => 'core.switch', 'label' => 'Même configuration WAF que l’espace Root', 'default' => false,
                    'description' => 'Utilise directement le mode, les motifs, le message et l’exemption ROOT du Space 1, sans chaîne d’héritage. Enregistrez pour appliquer votre choix.'],
            ]],
            ['id' => 'waf_settings', 'label' => 'WAF : fonctionnement', 'fields' => [
                ['type' => 'core.note', 'description' => 'Le WAF inspecte les chemins, les noms et valeurs des paramètres d’URL et des corps de formulaires/JSON, sur les routes résolues publiques et administratives. Cookies, en-têtes et fichiers téléversés ne sont pas analysés. Commencez par Observation : les motifs peuvent détecter du contenu légitime.'],
                ['name' => 'waf_mode', 'type' => 'core.select', 'label' => 'Mode du WAF', 'default' => 'off', 'options' => [
                    ['value' => 'off', 'label' => 'Désactivé'], ['value' => 'observe', 'label' => 'Observation : journaliser sans bloquer'],
                    ['value' => 'block', 'label' => 'Blocage : refuser avec HTTP 403'],
                ]],
                ['name' => 'waf_skip_root', 'type' => 'core.switch', 'label' => 'Ne pas analyser les requêtes des utilisateurs ROOT', 'default' => true,
                    'description' => 'ROOT est vérifié au serveur dans la session authentifiée (permission ROOT sur le Space racine). Les ACL, le CSRF et les validations restent actifs.'],
                ['name' => 'waf_message', 'type' => 'core.text', 'label' => 'Message de refus HTTP 403', 'default' => WafConfiguration::defaults()['waf_message'],
                    'required' => true, 'validation' => ['minLength' => 1, 'maxLength' => 500],
                    'description' => 'Texte simple sur une ligne, par exemple : Votre requête a été refusée. Contactez l’administrateur si le problème persiste. Le préfixe [WAF] est toujours ajouté. Les API ou clients demandant du JSON reçoivent le format Core, avec source=waf et error_code=WAF_BLOCKED ; les autres reçoivent une page HTML 403. Aucun motif ni contenu reçu n’est révélé.'],
            ]],
            ['id' => 'waf_patterns', 'label' => 'WAF : motifs', 'fields' => [
                ['type' => 'core.note', 'description' => 'Format JSON strict : "strings" est un tableau de chaînes littérales, recherchées sans distinction de casse ; "regexes" est un objet nom → expression PCRE complète, avec ses délimiteurs et options. Les virgules séparent les éléments JSON. Doubler chaque antislash dans les chaînes JSON : \\b s’écrit \\\\b. Pas de commentaires, de PHP ni de moteur de rules Joomla.'],
                ['name' => 'waf_patterns', 'type' => 'core.code', 'syntax' => 'json', 'label' => 'Chaînes et expressions régulières',
                    'default' => WafConfiguration::examplePatterns(), 'validation' => ['maxBytes' => WafConfiguration::MAX_JSON_BYTES],
                    'description' => 'Les exemples affichés sont modifiables. Pour ne rien détecter : {"strings":[],"regexes":{}}. Maximum 50 motifs au total, 256 octets par chaîne, 1024 par regex et 32768 pour le JSON. Les regex invalides sont refusées au serveur. Les noms de regex contiennent lettres ASCII, chiffres, tirets et soulignés, en commençant par une lettre. Évitez les signatures trop générales comme base64, iframe, # ou --.'],
            ]],
            ['id' => 'waf_developers', 'label' => 'WAF : développeurs et limites', 'fields' => [
                ['type' => 'core.note', 'label' => 'Exempter un contrôleur ou une action',
                    'description' => 'Importer use YTassy\\Tycms\\CoreExts\\Security\\ExtensionApi\\AnnotationsController\\SecurityDoNotCheckPatterns; puis placer #[SecurityDoNotCheckPatterns] sur la classe du contrôleur pour toutes ses actions, ou sur une méthode pour cette action seulement. Exemple : #[SecurityDoNotCheckPatterns] public function saveHtml(...) { ... }. Cette exemption s’applique à tous les appelants de la route et ignore uniquement les motifs WAF ; elle ne désactive jamais les ACL, le CSRF ou la validation métier. Préférez une exemption par méthode pour les éditeurs HTML/code. Les écrans Core qui reçoivent du HTML, du SCSS, des modèles d’e-mail ou des prompts IA sont déjà exemptés, comme l’enregistrement des configurations d’extension nécessaire pour configurer le WAF lui-même.'],
                ['type' => 'core.note', 'description' => 'Les limites du moteur sont fixes : 128 Kio par valeur, 512 Kio cumulés (variantes décodées comprises), 1024 nœuds, profondeur 20, 10000 comparaisons et budget de 50 ms vérifié entre comparaisons. Les limites PCRE bornent chaque regex, sans garantir un délai strict à la milliseconde. Dépassement ou erreur regex : journalisé en Observation, refusé en Blocage. Les données originales ne sont pas modifiées. Le WAF ne remplace pas les protections métier et ne couvre ni les fichiers statiques, ni les URL non résolues, ni l’updater autonome.'],
            ]],
        ];

        // Un seul onglet WAF ; les groupes servent uniquement à organiser les repères du formulaire.
        $fields = [];
        foreach ($groups as $group) {
            $fields[] = self::plainNote($group['label']);
            foreach ($group['fields'] as $field) {
                // Les explications WAF forment un texte continu, sans encadré bleu répété.
                $fields[] = ($field['type'] ?? null) === 'core.note'
                    ? [...$field, 'ui' => [...($field['ui'] ?? []), 'variant' => 'plain']] : $field;
            }
        }
        return [['id' => 'waf', 'label' => 'WAF', 'fields' => $fields]];
    }

    private static function plainNote(?string $label = null): array
    {
        return ['type' => 'core.note', 'label' => $label, 'ui' => ['variant' => 'plain']];
    }
}
