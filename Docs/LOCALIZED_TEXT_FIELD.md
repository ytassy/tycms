# Champ Vue de texte multilingue

`src/ria/src/components/common/LocalizedTextField.vue` est un contrôle de
saisie réutilisable. Il ne lit ni le store de pages, ni les langues du Space.
Le composant appelant lui fournit son contexte et conserve la valeur par
`v-model`. Le sélecteur de clés utilise un store de langue partagé et une API
de lecture propre au Space indiqué.

## Valeur et usage

Le modèle accepte deux formats :

```js
'Bienvenue'                            // texte commun à toutes les langues
{ fr: 'Bienvenue', en: 'Welcome' }     // variantes par langue
```

Une clé `LG_...` peut être saisie comme chaîne ou comme variante. Le contrôle
la conserve telle quelle : sa résolution appartient au rendu du champ métier.
Pour lire une variante côté Vue sans modifier le modèle, importer
`selectLocalizedText(value, locale)` depuis
`src/ria/src/components/common/localizedText.js`. Une langue absente ou
une variante vide donne une chaîne vide, sans repli implicite.

Exemple dans un composant qui dispose des langues actives et de la langue
affichée :

```vue
<script>
import LocalizedTextField from '../common/LocalizedTextField.vue'

export default {
    components: { LocalizedTextField },
    data: () => ({ title: '', locales: ['fr', 'en'], locale: 'fr', spaceId: 2 }),
}
</script>

<template>
    <LocalizedTextField v-model="title" label="Titre"
        :locales="locales" :locale="locale" :space-id="spaceId" :disabled="false" />
</template>
```

Le chemin d'import de l'exemple est à adapter à l'emplacement du composant
appelant. Le contrôle est importé localement ; il n'est pas enregistré dans
`registerGlobalComponents()`.

## Props

| Prop | Rôle |
| --- | --- |
| `modelValue` | Chaîne commune ou objet `{ locale: texte }`, piloté par `v-model`. |
| `label` | Libellé affiché au-dessus du contrôle. Obligatoire. |
| `locales` | Liste des codes de langue à éditer, fournie par l'écran appelant. |
| `locale` | Langue courante lors du passage du texte commun aux variantes et lors du retour au texte commun. Elle doit figurer dans `locales`. |
| `mode` | `text` par défaut, `textarea` pour plusieurs lignes, `html` pour CodeMirror HTML, ou `rich` pour TinyMCE. |
| `inputType` | Type de l'input en mode `text` ; `text` par défaut, `url` pour une URL. Le serveur reste responsable de sa validation. |
| `maxLength` | Limite HTML `maxlength` de l'input en mode `text` ; défaut : 500. N'impose aucune limite en modes `html` et `rich`. |
| `spaceId` | ID du Space de la valeur éditée. Obligatoire pour afficher le sélecteur de clés ; transmis aussi à TinyMCE pour les médias et les outils IA en mode `rich`. |
| `disabled` | Désactive la saisie et le changement de format. |

Le composant émet `update:modelValue` avec une nouvelle chaîne ou un nouvel
objet. Il ne modifie pas directement l'objet reçu. Le bouton « Traduire par
langue » apparaît seulement avec plusieurs langues ; le passage au format
multilingue place le texte commun dans `locale` et initialise les autres
variantes à vide. Le retour au texte commun demande une confirmation, car les
autres variantes sont alors supprimées. Cette confirmation passe par la modale
Question commune ; « Non » conserve l'objet de traductions sans émission de
`update:modelValue`.

## Sélecteur de clés `LG_`

Le bouton « Clé LG_ » apparaît quand `spaceId` est positif. En texte commun,
il remplace la chaîne entière ; en mode par langue, chaque ligne a son propre
bouton et seule cette variante est remplacée. La clé reste une référence brute
dans `v-model` : elle n'est jamais remplacée par son texte effectif lors de
l'édition. La saisie manuelle d'une clé reste possible.

À l'ouverture, le champ demande les clés déclarées et leur valeur effective
pour le Space et la langue de la ligne. Il recherche dans les noms et les
valeurs, affiche 30 résultats au plus et permet d'affiner la recherche.
L'aperçu affiche le HTML comme du texte échappé. Les surcharges modifiées dans
un autre écran sont relues à chaque ouverture. En mode `rich`, une référence
`LG_` utilise un input texte afin que TinyMCE ne transforme pas sa syntaxe.

L'API `GET /administrator/api/languages/selectable-keys?spaceId=…&locale=…`
exige `PAGE_READ` sur le Space demandé et une langue active de
ce Space. Elle ne donne pas le droit de modifier les traductions. Le store
`admin_language_keys` mutualise les demandes simultanées des champs ouverts.

Les contrôles `code_editor` et `rich_text_editor` sont enregistrés globalement
par l'application Vue. Le mode `rich` dépend de TinyMCE et utilise `spaceId`
pour les fonctions de médias et d'IA soumises aux droits existants. La palette de
couleurs est celle de [tous les TinyMCE](FRONTEND.md#couleurs-du-texte-enrichi),
alimentée par la template courante indépendamment de `spaceId`. Les styles
propres au champ sont inclus dans le composant.

## Responsabilité du serveur

Ce composant ne valide pas les codes de langue, ne purifie pas le HTML et ne
résout pas les clés `LG_`. Le workflow qui enregistre la valeur doit contrôler
les formats, la taille, les droits et chaque variante HTML. Pour les pages Core,
ce contrat est appliqué par `PageLocalizedText`, les composants et leur registre, puis le
rendu sélectionne la langue via `PageRenderer` ; voir
`Docs/PAGE_EDITOR.md`.
