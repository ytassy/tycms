# ObjectValidator

`ObjectValidator` valide les données issues de `json_decode($json, false)` : les
objets JSON restent des `stdClass` et les listes JSON restent des tableaux PHP.
Les schémas sont des tableaux PHP de confiance, chargés côté serveur ; ils ne
doivent jamais provenir de la requête HTTP.

```php
use YTassy\Tycms\Lib\ObjectValidator\ObjectValidator;

$schema = require __DIR__ . '/schemas/article.php';
$payload = json_decode($json, false, 512, JSON_THROW_ON_ERROR);

/** @var ObjectValidator $objectValidator */
$result = $objectValidator->normalizeAndValidate($payload, $schema);

if (!$result->isValid()) {
    foreach ($result->getErrors() as $error) {
        // $error->path, par exemple : /content/title
        // $error->keyword, par exemple : required ou minLength
    }
}
```

Exemple de `schemas/article.php` :

```php
<?php

return [
    'type' => 'object',
    'properties' => [
        'title' => [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 150,
        ],
        'publicationDate' => [
            'type' => 'string',
            'format' => 'date',
        ],
        'blocks' => [
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => ['text', 'image']],
                    'content' => ['type' => 'string'],
                ],
                'required' => ['type', 'content'],
            ],
        ],
    ],
    'required' => ['title', 'blocks'],
];
```

`additionalProperties` est à `false` par défaut. Pour l'ouvrir explicitement
sur un objet précis, utiliser `additionalProperties => true`, ou fournir un
sous-schéma afin de valider ces propriétés supplémentaires.

## Mots-clés pris en charge

- Général : `$ref` local, `type`, `const`, `enum`, `allOf`, `anyOf`, `oneOf`,
  `not`, `format`.
- Objet : `properties`, `required`, `additionalProperties`, `minProperties`,
  `maxProperties`.
- Liste : `items`, `minItems`, `maxItems`, `uniqueItems`.
- Texte : `minLength`, `maxLength`, `maxBytes`, `pattern` (PCRE).
- Nombre : `minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum`,
  `multipleOf`.

Les références sont locales uniquement. Un document compatible avec l'ancien
éditeur est aussi accepté : `['schemas_ref' => [...], 'schema' => [...]]`.
Les références peuvent alors s'écrire `'$ref' => 'address'` ; `$defs` et
`definitions` sont également pris en charge. Toute URL dans `$ref` est refusée.

## Formats

Les formats standards disponibles sont : `date`, `time`, `date-time`, `email`,
`uuid`, `ipv4`, `ipv6`, `hostname`, `uri`, `duration` et `json-pointer`.

Un plugin enregistre ses formats à la fin du chargement des extensions. Le nom
doit être préfixé par le plugin afin d'éviter les collisions :

```php
use YTassy\Tycms\Lib\ObjectValidator\ObjectValidator;

public function onAfterLoadExtensions(): void
{
    /** @var ObjectValidator $validator */
    $validator = $this->app->getOrAddSharedExtensionsClass(ObjectValidator::class);
    $validator->registerFormat('catalog.product-code', new ProductCodeFormat($this));
}
```

```php
use YTassy\Tycms\Lib\ObjectValidator\ObjectValidatorFormat;
use YTassy\Tycms\Lib\ObjectValidator\ObjectValidatorFormatContext;
use YTassy\Tycms\Lib\ObjectValidator\ObjectValidatorFormatResult;

final class ProductCodeFormat implements ObjectValidatorFormat
{
    public function validate(
        mixed $value,
        ObjectValidatorFormatContext $context,
    ): bool|ObjectValidatorFormatResult {
        if (!is_string($value) || !$this->existsInCatalog($value)) {
            return ObjectValidatorFormatResult::invalid('Ce produit n’existe pas.');
        }

        return true;
    }
}
```

Un format peut interroger la base de données, mais il ne remplace pas les ACL :
le WF reste responsable de l'autorisation métier.

## Normalizers

Un normalizer transforme explicitement une valeur de confiance avant sa
validation. `normalizeAndValidate()` retourne une copie normalisée dans
`$result->value` et ne modifie jamais l'objet reçu. `validate()` reste
disponible lorsqu'aucune transformation n'est voulue.

Le normalizer standard `html-sanitizer` utilise `HtmlSanitizer` :

```php
'content' => [
    'type' => 'string',
    'normalizer' => 'html-sanitizer',
    'maxLength' => 50_000,
],
```

Il peut y avoir un seul `normalizer`, ou une liste `normalizers` appliquée dans
l'ordre. Les normalizers ne sont jamais implicites : ils ne convertissent pas
automatiquement une chaîne en entier ou un booléen. Après nettoyage, les
contraintes (`maxLength`, `pattern`, `format`, etc.) sont vérifiées sur la
valeur nettoyée.

Un plugin peut enregistrer le sien via
`registerNormalizer('plugin.nom', new MyNormalizer())`. Il doit implémenter
`ObjectValidatorNormalizer`; son unique rôle est de retourner la valeur
transformée. Un normalizer ne remplace ni la validation métier ni les ACL du
WF.
