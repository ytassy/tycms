# Preuves du lot 02

Révision initiale : `df84bd5a9bf9e56766bb380471d7a101a69907d0`.
Consultation initiale des avis : `2026-11-18`. Actualisation Composer sur la
révision `b51758914201fd9ffc5cdcf3e7ebfa4602351e80`, avis consultés le
`2026-11-19` : [ComposerRefresh](ComposerRefresh/README.md). Voir le
[rapport](../../Reports/LOT-02.md).
Les résultats d'audit décrivent les bases d'avis consultées, pas une garantie
d'absence de vulnérabilité ni l'état de la production.

| Fichiers | État et portée |
| --- | --- |
| [contexte.json](contexte.json), [sources.json](sources.json) | Contexte et empreintes des huit manifestes/verrous **avant** correction. |
| [Inventaire](../../Inventories/DEPENDANCES-LOT-02.csv) | 503 entrées avant correction : Composer, npm et six bibliothèques copiées. Ce n'est pas une SBOM exhaustive du ZIP ou des images. |
| [composer-audit.json](composer-audit.json), [lms-audit.json](lms-audit.json) | Avis initiaux ; quatre avis Composer et deux avis npm distincts, sans additionner les dépendants signalés comme des failles différentes. |
| [composer-audit-after.json](composer-audit-after.json), [lms-audit-after.json](lms-audit-after.json) | Résultats après les seules mises à jour autorisées. |
| [ria-audit.json](ria-audit.json), [mermaid-audit.json](mermaid-audit.json) | Verrous inchangés ; résultats conservés, non relancés à la reprise. |
| [sources-after.json](sources-after.json) | Empreintes finales de la campagne initiale corrigée, avant les ajouts ultérieurs de Yann ; trois fichiers changés dans cette campagne. Les empreintes courantes sont dans ComposerRefresh. |
| [applicability.json](applicability.json), [applicability-after.json](applicability-after.json) | Huit sondes par état ; le calcul filesystem vulnérable est reproduit avant, refusé après. Les protections applicatives testées refusent les entrées dans les deux états. |
| [sondes.php](sondes.php) | Script initial : attend le primitif vulnérable. **Il n'est pas attendu vert avec les versions corrigées.** Pour contrôler l'état corrigé, inverser uniquement cette assertion comme expliqué ci-dessous. |
| [verification.json](verification.json) | Synthèse des tests/build conservés et du contrôle du verrou à la reprise. |
| [static-comparison.json](static-comparison.json), [codemirror-cdn-comparison.json](codemirror-cdn-comparison.json) | Comparaison de 12 fichiers : neuf identiques aux paquets npm, trois CodeMirror identiques aux versions minifiées jsDelivr documentées. Les écarts avec les fichiers npm non minifiés ne prouvent pas une altération. |
| [static-advisories.json](static-advisories.json) | Réponse vide pour les avis directs des bibliothèques copiées consultées ; aucune attestation exhaustive de leurs transitives embarquées. |
| [secrets-synthese.json](secrets-synthese.json) | Couverture heuristique expurgée, sans valeur ni empreinte de secret. Les sorties brutes ne font pas partie des preuves publiables. |

Les empreintes des fichiers sources/assets servent uniquement à identifier du
code public ; elles ne sont pas des empreintes de valeurs secrètes.

## Reproduction des sondes

Le script charge seulement l'autoload installé, pas la configuration locale,
une session ou SQL. Il crée deux SCSS factices sous
`/tmp/tyframework-security-lot02/scss-fixture` et ne lit aucun secret. Pour le
rejouer dans un autre environnement, utiliser un dossier temporaire isolé
(préférer `/tmp/opencode`) et adapter ce chemin dans une copie du script.

Avant correction, l'assertion `filesystem_primitif_amont_reproduit` attend
`Path::isBasePath('/base', '/base/../x://../secret.txt') === true`.
Après correction, la nommer `filesystem_primitif_amont_refuse` et attendre
`!Path::isBasePath('/base', '/base/../x://../secret.txt')` ; les sept autres
assertions restent identiques. Cela explique les clés différentes des deux JSON.

Les fichiers temporaires de la campagne ont servi à récupérer les résultats
déjà acquis. Les conclusions nécessaires sont conservées ici ; leur lecture ne
dépend plus de la présence de ces temporaires.
