<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Mcp;

/**
 * Documentation de domaine du CMS, pour un agent qui doit le comprendre.
 *
 * `McpLanguageGuide` explique comment écrire un programme ; ce document
 * explique ce que le site contient et comment ses objets s'articulent. Sans
 * lui, un agent sait appeler `core::createPageMenuItem()` mais ignore qu'une
 * entrée pointe vers une page publiée du même Space, qu'un menu appartient à
 * un seul Space, ou qu'une page se compose de corps typés.
 *
 * Les règles décrites ici sont celles du code : elles suivent `Docs/MEDIAS.md`,
 * `Docs/PAGE_COMPONENTS.md`, `Docs/USERS.md` et `Docs/ROLES.md`. Toute
 * évolution du modèle doit être répercutée ici.
 */
final class McpDomainGuide
{
    /**
     * Modèle de données, du plus large au plus fin.
     *
     * @return array<string, mixed>
     */
    public static function model(): array
    {
        return [
            'hierarchy' => [
                'Un SPACE est un site. Tout le reste lui appartient : utilisateurs, menus, pages, médias, rôles.',
                'Les Spaces forment une arborescence : le Space racine (parent_id vide) porte l’administration, '
                    . 'les autres sont des sites de contenu.',
                'Un utilisateur est rattaché à un Space, et peut appartenir à d’autres Spaces par ses groupes.',
                'Un MENU appartient à un seul Space et contient des ENTRÉES organisées en arbre.',
                'Une PAGE appartient à un Space. Elle est atteinte par une entrée de menu, pas directement.',
            ],
            'space' => [
                'id' => 'Identifiant.',
                'title' => 'Nom affiché.',
                'parentId' => 'Space parent ; vide pour le Space racine.',
                'template' => 'Template d’affichage du site.',
                'mediasDir' => 'Dossier des médias, relatif à la racine des médias.',
                'hosts' => 'Domaines qui servent ce Space.',
                'params' => 'Réglages du Space, dont l’attribution automatique de rôles.',
            ],
            'page' => [
                'id' => 'Identifiant.',
                'spaceId' => 'Space propriétaire ; une entrée de menu ne peut viser qu’une page de son Space.',
                'name' => 'Nom interne de la page.',
                'description' => 'Description libre.',
                'status' => '1 publiée, 0 dépubliée, -1 et -2 dans la corbeille. Seul un statut -2 permet la '
                    . 'suppression définitive.',
                'type' => '1 page en HTML brut, réservée aux comptes ROOT ; 2 page composée de corps typés.',
                'data' => 'Document JSON des corps, pour une page de type 2.',
                'rawScss' => 'Styles propres à la page, compilés par le CMS.',
            ],
            'pageDocument' => [
                'principe' => 'Une page de type 2 est un arbre de corps. Chaque corps a un `id` unique, un `type` '
                    . 'parmi le catalogue des composants, des `props` propres à son type, et des `zones` '
                    . 'contenant d’autres corps.',
                'version' => 'Le document porte `version: 3`. Un document d’une autre version est refusé.',
                'squelette' => [
                    'version' => 3,
                    'container' => ['fluid' => false],
                    'items' => [
                        [
                            'id' => 'row_1',
                            'type' => 'core.row',
                            'props' => [],
                            'zones' => [
                                'columns' => [
                                    [
                                        'id' => 'column_1',
                                        'sizes' => ['xs' => 12, 'md' => 6],
                                        'label' => '',
                                        'icon' => '',
                                        'props' => [],
                                        'items' => [],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'regle' => 'Appelez core::listPageComponents() avant de composer : le catalogue donne les types '
                    . 'disponibles, leurs zones et leurs propriétés. Ne devinez jamais un `type` ni une `props`.',
            ],
            'menu' => [
                'id' => 'Identifiant.',
                'spaceId' => 'Space propriétaire ; un menu n’appartient qu’à un Space.',
                'name' => 'Nom du menu, unique dans le Space.',
                'alias' => 'Alias technique facultatif.',
                'items' => 'Entrées du menu, organisées par `parentId`.',
            ],
            'menuItem' => [
                'id' => 'Identifiant.',
                'menuId' => 'Menu propriétaire.',
                'parentId' => 'Entrée parente ; vide pour une entrée racine.',
                'title' => 'Libellé affiché.',
                'type' => 'Type d’entrée : `page`, `text`, `separator`, ou un type fourni par une extension.',
                'params' => 'Paramètres propres au type. Une entrée `page` exige `page_id`.',
                'status' => '1 publiée, 0 dépubliée, -1 et -2 dans la corbeille.',
                'ordering' => 'Position parmi les entrées de même parent.',
                'alias' => 'Alias technique.',
                'icon' => 'Classe d’icône.',
                'displayInMenu' => 'Faux masque l’entrée du menu sans la supprimer.',
            ],
            'user' => [
                'id' => 'Identifiant.',
                'spaceId' => 'Space de rattachement.',
                'type' => '`user` pour un compte ordinaire, `service` pour un compte technique sans connexion.',
                'firstName', 'lastName', 'email', 'timezone' => 'Identité du compte.',
                'blocked' => 'Compte bloqué : il ne peut plus se connecter.',
                'groups' => 'Groupes d’appartenance.',
                'roles' => 'Rôles attribués directement.',
            ],
            'acl' => [
                'principe' => 'Un compte n’a pas de droits directs. Il appartient à des GROUPES, qui portent des '
                    . 'RÔLES, qui portent des PERMISSIONS. Les groupes parents comptent.',
                'permission' => 'Droit nommé, par exemple `plg_core__PAGE_CRUD`. Une permission peut en exiger '
                    . 'd’autres : le rôle doit les contenir toutes.',
                'role' => 'Ensemble de permissions, propre à un Space ou global. Les rôles système ne sont pas '
                    . 'supprimables.',
                'group' => 'Ensemble d’utilisateurs portant des rôles. Les groupes personnels sont masqués ; '
                    . 'les groupes système Invité et Utilisateurs ont des rôles fixes.',
                'root' => 'Un compte est ROOT quand il détient la permission `plg_core__ROOT`. C’est le niveau '
                    . 'le plus élevé : il ne peut pas être délégué.',
            ],
            'media' => [
                'principe' => 'Chaque Space possède une arborescence de médias. Les chemins sont relatifs à sa '
                    . 'racine.',
                'editable' => 'Seuls `.txt` et `.scss` sont éditables comme texte. Les autres formats demandent '
                    . 'un envoi binaire, hors du langage.',
                'rootOnly' => 'Les extensions réservées par la configuration (par défaut `scss` et `js`) ne sont '
                    . 'modifiables que par un compte ROOT : elles changent le comportement du site après coup.',
                'svg' => 'Un SVG est contrôlé à l’envoi : script, référence externe et gestionnaire d’événement '
                    . 'sont refusés.',
            ],
        ];
    }

    /**
     * Parcours type d'un agent, du plus simple au plus complet.
     *
     * @return array<string, mixed>
     */
    public static function workflows(): array
    {
        return [
            [
                'title' => 'Ajouter une page au menu',
                'steps' => [
                    'Lire les pages existantes du Space pour connaître les identifiants.',
                    'Appeler core::createPageMenuItem(menuId, titre, pageId).',
                    'La page doit être publiée (status 1) et appartenir au même Space que le menu.',
                ],
            ],
            [
                'title' => 'Créer un menu complet',
                'steps' => [
                    'Appeler core::createMenu(spaceId, nom).',
                    'Créer les entrées racines avec les actions de type.',
                    'Pour un sous-menu, passer l’identifiant de l’entrée racine en `parentId`.',
                ],
            ],
            [
                'title' => 'Donner des droits à un compte',
                'steps' => [
                    'Lire les rôles avec core::listRoles() et les groupes avec core::listGroups().',
                    'Créer ou modifier le rôle avec les permissions de core::listPermissions().',
                    'Attribuer le rôle au groupe, puis le compte au groupe.',
                    'Un compte n’a jamais de droit direct : toujours passer par groupe et rôle.',
                ],
            ],
            [
                'title' => 'Modifier un fichier de style',
                'steps' => [
                    'Lire le fichier avec core::readMediaFile(spaceId, chemin) : la réponse porte une `version`.',
                    'Appeler core::saveMediaFile avec le contenu complet et cette `version`.',
                    'Une version périmée signifie qu’un humain a modifié le fichier entre-temps : relire avant d’écraser.',
                ],
            ],
        ];
    }

    /** Rendu Markdown, ajouté à la documentation servie. */
    public static function markdown(): string
    {
        $model = self::model();

        $lines = [
            '# TyCMS — modèle du site',
            '',
            'Ce document décrit ce que contient le site et comment ses objets s’articulent. '
                . 'Il complète le guide du langage : celui-ci dit comment écrire un programme, '
                . 'celui-ci dit sur quoi le programme agit.',
            '',
            '## Organisation générale',
            '',
        ];

        foreach ($model['hierarchy'] as $line) {
            $lines[] = '- ' . $line;
        }

        foreach ([
            'space' => 'Space',
            'page' => 'Page',
            'menu' => 'Menu',
            'menuItem' => 'Entrée de menu',
            'user' => 'Utilisateur',
        ] as $key => $title) {
            $lines[] = '';
            $lines[] = '## ' . $title;
            $lines[] = '';

            foreach ($model[$key] as $field => $description) {
                $lines[] = '- `' . $field . '` — ' . $description;
            }
        }

        $lines[] = '';
        $lines[] = '## Document d’une page';
        $lines[] = '';
        $lines[] = $model['pageDocument']['principe'];
        $lines[] = '';
        $lines[] = $model['pageDocument']['version'];
        $lines[] = '';
        $lines[] = '```json';
        array_push($lines, ...explode("\n", (string) json_encode(
            $model['pageDocument']['squelette'],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        )));
        $lines[] = '```';
        $lines[] = '';
        $lines[] = '> ' . $model['pageDocument']['regle'];

        $lines[] = '';
        $lines[] = '## Droits d’accès';
        $lines[] = '';

        foreach ($model['acl'] as $key => $description) {
            $lines[] = '- **' . $key . '** — ' . $description;
        }

        $lines[] = '';
        $lines[] = '## Médias';
        $lines[] = '';

        foreach ($model['media'] as $key => $description) {
            $lines[] = '- **' . $key . '** — ' . $description;
        }

        $lines[] = '';
        $lines[] = '## Parcours courants';
        $lines[] = '';

        foreach (self::workflows() as $index => $workflow) {
            $lines[] = '### ' . ($index + 1) . '. ' . $workflow['title'];
            $lines[] = '';

            foreach ($workflow['steps'] as $step) {
                $lines[] = '- ' . $step;
            }

            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
