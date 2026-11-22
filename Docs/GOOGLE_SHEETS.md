# Google Sheets et automatisation

Les actions `google_sheets::*` permettent à un scénario de lire et d’écrire dans
un classeur Google Sheets avec un compte de service.

## Préparation Google

1. Créer un compte de service dans Google Cloud et activer l’API Google Sheets.
2. Télécharger sa clé JSON.
3. Partager chaque classeur utilisé avec l’adresse `client_email` du compte de
   service, en éditeur. Un accès limité aux seuls classeurs nécessaires est
   recommandé.
4. Coller le JSON complet dans **Automatisation → Configuration → Google API
   (Sheets et Agenda)**, pour le Space concerné.

La configuration est propre à chaque Space. Le JSON est chiffré au repos avec
le secret de l’installation et affiché en clair aux administrateurs autorisés
pour permettre son édition. Il ne doit jamais être journalisé ni transmis à un
scénario.

La permission `plg_automation__ADMIN` contrôle l’édition de cette configuration
et des autres réglages sensibles de l’extension. Elle n’est pas exposée à
l’édition ordinaire des rôles : ROOT l’attribue explicitement.

## Identifiants

- `spreadsheet_id` : identifiant du classeur, visible dans son URL.
- `sheet_id` : facultatif. ID numérique de l’onglet, ou son titre.
  - `null` : utiliser `range`.
  - entier ou chaîne numérique : ID Google de l’onglet, résolu en titre.
  - autre chaîne : titre de l’onglet.
- `range` : plage A1, par exemple `Inscriptions!A:Z`. Si `sheet_id` est fourni
  avec une plage relative (`A2:C5`), le titre est ajouté automatiquement.

`listSheets` retourne l’ID et le titre de chaque onglet, ce qui évite de les
deviner.

## Actions

| Action | Rôle |
| --- | --- |
| `google_sheets::appendRow` | Ajouter une ligne à la fin d’une feuille. |
| `google_sheets::appendRows` | Ajouter plusieurs lignes. |
| `google_sheets::insertRow` | Insérer une ligne à une position précise. |
| `google_sheets::readRange` | Lire une plage A1. |
| `google_sheets::readSheet` | Lire toutes les valeurs utilisées d’une feuille. |
| `google_sheets::updateRange` | Écrire dans une plage existante. |
| `google_sheets::listSheets` | Lister les onglets d’un classeur. |
| `google_sheets::findRows` | Chercher une valeur dans une colonne. |
| `google_sheets::upsertRow` | Mettre à jour la première ligne correspondante, ou l’ajouter. |
| `google_sheets::clearRange` | Effacer une plage. Action destructive. |

### Exemples

```php
google_sheets::appendRow("1AbC...", "Inscriptions", [
    $input->email,
    $input->nom,
    date::toIso(date::now())
]);
```

```php
// Insère avant la ligne 2 ; les lignes suivantes sont décalées vers le bas.
google_sheets::insertRow("1AbC...", "Inscriptions", 2, [
    $input->email,
    $input->nom,
    "nouveau"
]);
```

`insertRow` utilise une position à partir de 1 et insère une ligne entière
(`InsertDimension`), ce qui évite les conflits avec les tableaux Google Sheets.
Les chaînes sont écrites littéralement, sans interprétation de formule.

```php
$lignes = google_sheets::readSheet("1AbC...", "Inscriptions");
```

```php
$trouvees = google_sheets::findRows("1AbC...", "Inscriptions", "A", $input->email);

if (arr::isEmpty($trouvees)) {
    google_sheets::appendRow("1AbC...", "Inscriptions", [$input->email, $input->nom]);
}
```

```php
$resultat = google_sheets::upsertRow(
    "1AbC...",
    "Inscriptions",
    "A",
    $input->email,
    [$input->email, $input->nom, "à jour"]
);
```

`upsertRow` retourne `{"action":"updated","row":12}` ou
`{"action":"appended","row":null}`.

## Options de saisie

`value_input_option` vaut `RAW` par défaut : les valeurs sont écrites comme du
texte brut, ce qui évite qu’une donnée de webhook commençant par `=` devienne
une formule Google Sheets.

`USER_ENTERED` laisse Google interpréter les dates, nombres et formules. À
utiliser uniquement avec des données contrôlées.

`value_render_option` vaut `FORMATTED_VALUE` par défaut ; `UNFORMATTED_VALUE`
et `FORMULA` sont disponibles en lecture.

## Sécurité et limites

- Le compte de service doit avoir accès uniquement aux classeurs nécessaires.
- La clé privée est un secret d’installation : ne pas la placer dans un
  scénario, un journal ou un dépôt.
- `clearRange` exige une plage explicite.
- Les actions Google sont des effets `EXTERNAL` ; publier un scénario qui les
  utilise exige `plg_automation__PUBLISH`.
- Les appels HTTP ont un timeout court. Les erreurs Google sont journalisées
  sous `GOOGLE_API_ERROR` avec le statut HTTP, une raison bornée, un code
  interne et le nom de l’action ; le message brut du fournisseur n’est pas
  copié et la clé n’est jamais exposée. Le scénario reçoit un diagnostic
  contrôlé.
