# Actualisation Composer du lot 02

Révision : `b51758914201fd9ffc5cdcf3e7ebfa4602351e80`.
Avis consultés le `2026-11-19`. Analyse seule des ajouts de Yann : aucun
manifeste, vendor ou script de build modifié par l'analyse. Yann a ensuite
autorisé la correction du verrou ; seule son empreinte `content-hash` change.

| Preuve | Portée |
| --- | --- |
| [sources.json](sources.json), [sources-after.json](sources-after.json) | Empreintes des huit fichiers avant/après resynchronisation ; seuls les deux fichiers Composer diffèrent de la campagne initiale corrigée. |
| [delta.json](delta.json) | Treize paquets ajoutés, dont trois dépendances directes ; 36 paquets de production et 27 de développement. Aucune suppression ni modification de version des paquets déjà présents après correction. |
| [Inventaire Composer courant](../../../Inventories/COMPOSER-CURRENT-LOT-02.csv) | Les 63 paquets verrouillés, leur provenance déclarée et leur référence ; ce n'est pas une attestation des archives installées ni une SBOM de production. |
| [composer-audit.json](composer-audit.json) | Réponse réseau Packagist : aucun avis ni paquet abandonné ; toutes les dépendances verrouillées, développement compris. |
| [composer-validate.txt](composer-validate.txt) | Code de sortie 2 : verrou désynchronisé ; avertissements de licence et double déclaration de larapack/dd. |
| [correction.json](correction.json), [composer-lock-refresh.txt](composer-lock-refresh.txt), [composer-validate-after.txt](composer-validate-after.txt) | Rafraîchissement autorisé, seul content-hash modifié ; 63 paquets strictement identiques ; validation finale code 0 avec les deux avertissements préexistants. |
| [composer-platform.json](composer-platform.json) | Exigences satisfaites par PHP 8.5.4 et les extensions de l'hôte ; Docker/hébergement non vérifiés. |
| [verification.json](verification.json) | Résultats d'analyse avant correction et limites ; aucun nouveau test applicatif/build ni appel aux API Google/AWS. |

Les commandes ont utilisé uniquement des copies des deux fichiers Composer,
un répertoire Composer et un cache isolés, avec `--no-plugins --no-scripts`.
Le premier essai réseau n'a pas pu résoudre Packagist ; le résultat publié est
celui de la relance avec accès réseau. Aucun échec réseau n'est compté comme
un audit sans avis.

Pour reproduire, copier les deux fichiers de la révision indiquée dans un
répertoire temporaire, puis y exécuter avec un `COMPOSER_HOME` isolé :

```bash
composer --no-plugins --no-scripts audit --locked --format=json
composer --no-plugins --no-scripts validate --no-check-publish
composer --no-plugins --no-scripts check-platform-reqs --lock --format=json
```

Avant correction, le calcul `Composer\Package\Locker::getContentHash` confirme
que le verrou correspond au manifeste **sans son nouveau champ `extra`**. Il ne faut pas
modifier cette empreinte manuellement pour masquer une résolution incohérente.
Le constat et sa correction figurent dans [SEC-007](../../../Findings/Resolved/SEC-007.md).
