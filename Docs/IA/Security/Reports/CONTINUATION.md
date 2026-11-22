# Point de reprise de l’audit

État : **lot 03 analysé ; SEC-008, SEC-011 et SEC-012 corrigées après décision
explicite de Yann et vérifiées localement**. Aucun changement de dépendance.
SEC-009, SEC-010, DOC-003 et les points ouverts du lot 02 restent ouverts.
Ne lancer automatiquement ni autre correction ni lot suivant.

Références : [lot 03](LOT-03.md), [preuves de correction](../Evidence/LOT-03/Fixes/README.md),
[suivi](../SUIVI.md), [constats](../Findings/README.md), [lot 02](LOT-02.md)
et [actualisation Composer](../Evidence/LOT-02/ComposerRefresh/README.md).

## Périmètre et décisions en vigueur

- Dépôt complet avec essais locaux isolés ; hébergement réel ultérieurement.
- Médias/HLS publics par URL ; administrateurs de Space et auteurs de scénarios
  de confiance limitée ; Automatisation activée selon Yann.
- Hébergement cible mutualisé. Yann considère les en-têtes proxy normalement
  protégés ; aucune configuration de nettoyage n'est attestée. Distinguer
  comportement PHP et exposition réelle au serveur, sans risque accepté implicite.
- Analyse sujet par sujet, puis décision sur les corrections. Yann a choisi
  les correctifs simples SEC-011, SEC-012 et SEC-008 : redirections internes
  par défaut dans le framework, départ SSO externe explicite, vérification des
  formes de paramètres et CSRF du formulaire historique de choix de Space.
- Ne pas engager de banc navigateur complexe ; confier les essais manuels
  simples à Yann sur une instance locale isolée, jamais sur la production.
- Organisation : Findings par état, Reports, Evidence et Inventories ; IDs stables.

## Lot 03 : état courant

Analyse initiale : `1aff26d92a78bccc5d66b067f13dd7614f58e6bc`.
Base des corrections : `50cdf55d5ead8891835996b9824f5f649e7b5cf3`, arbre propre
au départ. Correctifs locaux non commités ; leurs empreintes sont conservées
avec les preuves, sans dépendre d'un répertoire temporaire.

- SEC-011 résolue localement : `RedirectResponse` exige par défaut un chemin
  commençant par un seul `/`, sans antislash, espace brut ou contrôle. Le runner
  revient à `/` si l'URI après erreur de formulaire est ambiguë ; query et chemin
  légitimes conservés. Le départ SSO utilise `external()` (HTTPS, HTTP local),
  le WF gardant le choix du fournisseur. Aucun domaine reconstruit depuis Host.
- SEC-012 résolue localement : forme contrôlée avant regex/JSON ; HTTP 400
  avant contrôleur/transaction pour les erreurs de paramètres, CSRF API prioritaire.
  Les JSON sérialisés structurés et paramètres facultatifs restent admis.
- SEC-008 résolue localement : jeton transmis par le formulaire historique,
  vérifié avant appel au helper de Space ou changement du contexte de session.
  La vérification de l'autorisation métier du Space cible reste au lot 04.
- **167 tests/515 assertions**, sans échec ni notice : Routing, Request,
  Response, Security, SpaceSelection, Sso et MyAccount. Sessions mémoire et
  doubles SQL ; pas d'Application initialisée, de configuration locale ni de réseau.
  Dix fichiers PHP passent le lint. Client API relu pour les réponses 400 ;
  aucun frontend modifié ni build nécessaire.
- Inventaire HTTP recoupé : 315 déclarations, 164 candidates hors Matrix/Test,
  150 API/14 non API, 57 GET/107 POST, 101 POST avec CSRF automatique,
  22 NoTransaction, 53 exemptions WAF. Lignes et paramètre CSRF de Space actualisés.
- Les six sondes/24 assertions de l'analyse initiale attendent parfois le défaut :
  elles restent figées sur l'ancienne révision. Ne pas les utiliser pour valider
  les corrections. Les 111 tests/327 assertions initiaux sont conservés également.
- Restent ouverts SEC-009 (logout GET, faible), SEC-010 (Host/proxy, modérée
  potentielle, protection serveur inconnue) et DOC-003 (en-têtes FileResponse
  ignorés par l'émission spécialisée ; usage Markdown en pièce jointe).
- Aucun accès inter-Space, détournement de compte, fuite OAuth ou déni de service
  global démontré. Aucun statut de conformité intégrale ASVS validé.
- L'intégration Google Sheets ajoutée depuis l'analyse initiale n'est pas auditée
  par ces trois corrections ; la prévoir aux lots 11/12/13.

## Lot 02 : acquis et ouverts conservés

- Symfony Filesystem/HtmlSanitizer 8.1.11 et Vue LMS 3.5.43/csstype 3.2.4
  corrigés et vérifiés lors de la campagne initiale autorisée.
- Ajouts Composer analysés à b517589 : Flysystem, adaptateur S3 et Google API
  Client ; treize paquets nouveaux, 36 production/27 développement. Aucun paquet
  existant changé de version. Audit d'alors sans avis ni paquet abandonné ;
  pas de certification de leurs intégrations applicatives.
- SEC-007 résolue : resynchronisation de content-hash autorisée, sans installation
  ni script/plugin, 63 paquets identiques ; validate réussi avec avertissements
  préexistants licence/double larapack/dd.
- Les huit manifestes/verrous restent identiques aux preuves finales de cette
  actualisation ; pas de rafraîchissement réseau Composer/npm lors des correctifs.
- SEC-006 picomatch reste UnderReview : pas de correction forcée ni rétrogradation
  majeure, accessibilité du motif à établir.
- Nettoyage Google du build à décider au lot 14 ; --no-scripts conservé.
  Images/SBOM, transitives des bundles copiés et ZIP final restent à examiner.
- Ancienne valeur SQL de l'historique déclarée jamais utilisée en production
  par Yann ; nouveau dépôt envisagé, aucune rotation ni réécriture par l'audit.
- Aucun nouveau scan global/historique de secrets lors de ces correctifs.

## Suite

Sur une instance locale isolée, Yann peut vérifier le choix de Space depuis
le formulaire et le départ SSO habituel. Le protocole complet OIDC/OAuth et
l'hébergement ne sont pas validés par ces tests unitaires. Le chemin HTML
400 est relu mais non exercé jusqu'à l'émission (ErrorPages termine le script).

Présenter séparément les choix pour SEC-009, SEC-010 et DOC-003 avant de les
modifier. Le lot suivant du plan est le 04 (ACL et Spaces), à lancer sur demande.
Sessions au 06, SSO/OAuth au 07, automatisation au 12, configuration serveur au 16.
Ne pas assimiler les tests unitaires à une validation navigateur, Apache ou déploiement.
