# Correctifs SEC-008, SEC-011 et SEC-012

Autorisation : Yann demande de coder les trois correctifs avec la solution
simple proposée. Base : `50cdf55d5ead8891835996b9824f5f649e7b5cf3`, arbre propre
au départ. Résultat local non commité, identifié par [sources.json](sources.json).
Les preuves initiales du [lot 03](../README.md) restent inchangées et distinctes.

## Contrôles et résultats

| Preuve | Résultat et portée |
| --- | --- |
| [phpunit.xml](phpunit.xml), [phpunit-result.txt](phpunit-result.txt) | PHP 8.5.4, PHPUnit 13.3.6 ; **167 tests/515 assertions**, sans échec ni notice. Routing, Request, Response, Security, SpaceSelection, Sso, MyAccount. |
| [sources.json](sources.json) | Empreintes après correction des sept fichiers PHP applicatifs, des trois fichiers de tests concernés et du client API relu. Aucune configuration locale ou empreinte de secret. |
| [lint-result.txt](lint-result.txt) | Dix fichiers PHP concernés valides. |
| [routes.json](routes.json), [ROUTES.csv](../../../ROUTES.csv) | 315 déclarations recoupées ; verbes/chemins conservés, lignes actualisées et jeton CSRF ajouté au POST historique de Space. |
| [dependencies.json](dependencies.json) | Huit manifestes/verrous inchangés depuis les preuves finales ComposerRefresh du lot 02 ; aucun audit réseau réexécuté. |
| [checks.json](checks.json) | Liens Markdown, intégrité JSON/CSV, empreintes et contrôle du diff. |

Les tests de sécurité ajoutés ou complétés sont
[RedirectResponseTest](../../../../../../src/tests/Unit/Response/RedirectResponseTest.php),
[RouterTest](../../../../../../src/tests/Unit/Routing/RouterTest.php) et
[HttpSecurityTest](../../../../../../src/tests/Unit/Routing/HttpSecurityTest.php).
Ils couvrent les références réseau, URI absolues, antislashs et contrôles,
la conservation des chemins/query légitimes, le départ externe explicite,
les tableaux/objets à la place des types déclarés, les JSON sérialisés légitimes,
le refus API 400 sans contrôleur/SQL et le choix de Space sans/faux/bon jeton.
Le formulaire rendu contient le jeton échappé ; le faux jeton ne charge pas
le helper et ne change pas le contexte en mémoire.

Le client API existant traite les réponses d'erreur JSON dans les rejets axios ;
lecture du code seulement, sans modification ni build frontend.
Le renvoi interne n'utilise ni Host ni proxy. La validation externe vérifie
le format HTTPS (ou HTTP local), pas l'autorisation d'un fournisseur : celle-ci
reste à son WF dans le bon Space.

## Rejouer localement

Depuis la racine, avec le vendor déjà installé :

```bash
php src/releases/vdev/vendor/bin/phpunit --configuration Docs/IA/Security/Evidence/LOT-03/Fixes/phpunit.xml --do-not-record-test-run-history --no-progress
php Docs/IA/Security/Evidence/LOT-03/extract-routes.php
git -c core.whitespace=cr-at-eol diff --check
```

Le bootstrap charge uniquement la configuration de test héritée, jamais celle
du site. L'Application n'est pas initialisée ; sessions mémoire et SQL simulé.
Les journaux et le cache PHPUnit restent sous `/tmp`. L'extraction ne démarre
pas Application et ne modifie pas elle-même l'inventaire.

## Limites et suite

Aucune requête de production, connexion SQL, interaction navigateur ou appel
SSO externe. Le chemin HTML 400 est relu mais pas exécuté jusqu'à l'émission,
car ErrorPages termine le script. Le contrôle des Spaces réellement autorisés
reste au lot 04 ; la frontière proxy, le transport et les cookies restent à
valider avec Yann. SEC-009, SEC-010 et DOC-003 demeurent ouverts ; aucun risque
accepté implicitement. Les points ouverts du lot 02 sont conservés, notamment
SEC-006, le packaging Google, les images et les artefacts finaux.
