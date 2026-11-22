# Constats de sécurité

Une fiche par cause principale, identifiant `SEC-NNN` stable même si son état
change. Le registre courant est dans [SUIVI.md](../SUIVI.md) ; les résultats et
limites du lot sont dans son rapport. Ne pas recopier la fiche dans chaque lot.

| Catégorie | Signification |
| --- | --- |
| `UnderReview` | Applicabilité, preuve ou qualification encore incomplète ; ni risque accepté ni faux positif établi. |
| `Confirmed` | Constat confirmé, en attente de décision ou de correction/vérification. |
| `Resolved` | Périmètre de correction décidé et vérifié, avec ses limites et conditions de réouverture. Cela ne prouve pas le déploiement. |
| `AcceptedRisks` | Acceptation **explicite** par Yann, avec motif, périmètre et conditions de réexamen. |
| `Dismissed` | Hypothèse écartée avec preuve et raison conservées. |

Les catégories sans fiche ne nécessitent pas de dossier vide. Déplacer la
fiche et actualiser les liens lors d'un changement d'état ; conserver son ID.
Une dépendance affectée, un scénario applicatif joignable et une exploitation
reproduite sont trois niveaux de preuve différents. La gravité amont n'est
pas attribuée automatiquement au projet.

## Lot 02

- Résolues localement : [SEC-001](Resolved/SEC-001.md) (Filesystem),
  [SEC-002](Resolved/SEC-002.md), [SEC-003](Resolved/SEC-003.md) et
  [SEC-004](Resolved/SEC-004.md) (trois causes HtmlSanitizer),
  [SEC-005](Resolved/SEC-005.md) (Vue SSR dans le verrou LMS).
- Résolue localement : [SEC-007](Resolved/SEC-007.md), resynchronisation du verrou
  Composer ; seule son empreinte change, 63 paquets identiques et validation
  réussie. Constat de reproductibilité du build, pas de compromission démontrée.
- Ouverte : [SEC-006](UnderReview/SEC-006.md), picomatch ; version affectée
  confirmée, accessibilité depuis une entrée non fiable à établir.
- Aucun risque accepté ni hypothèse écartée implicitement. L'ancienne valeur
  SQL de l'historique, déclarée non utilisée en production par Yann, est
  décrite avec ses limites dans le [rapport](../Reports/LOT-02.md#secrets-et-historique).


## Lot 03

- Résolues après autorisation et vérifications locales :
  [SEC-008](Resolved/SEC-008.md) (CSRF du choix de Space),
  [SEC-011](Resolved/SEC-011.md) (redirection interne bornée),
  [SEC-012](Resolved/SEC-012.md) (forme des paramètres et refus 400).
- Confirmée et ouverte : [SEC-009](Confirmed/SEC-009.md), logout GET, faible.
- En étude : [SEC-010](UnderReview/SEC-010.md), Host/proxy, modérée potentielle ;
  protection du serveur non attestée. DOC-003 (en-têtes FileResponse) reste ouvert.
- Aucun risque accepté par l'hypothèse mutualisée ; aucun déploiement vérifié.
  Les preuves ne démontrent ni contournement ACL ni compromission de compte.
