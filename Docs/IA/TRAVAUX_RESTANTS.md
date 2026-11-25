# Travaux restants et pistes ouvertes

Ce fichier distingue les limites du code et les idées à discuter. Il ne vaut
ni commande de réalisation, ni décision d'architecture. Avant un chantier,
vérifier le code et le guide lié ; préciser avec Yann les choix qui changent
le résultat. Les détails des fonctionnalités livrées restent dans les guides.

## Limites actuelles

| Domaine | Travail restant / contrainte | Contrat actuel |
| --- | --- | --- |
| Production | Image Docker de production, volumes persistants et pipeline de publication à construire et valider. Le dépôt fournit l'environnement de développement. | [Architecture](../ARCHITECTURE.md#persistance-et-préparation-de-la-production), [Docker](../../docker/dev/README.md) |
| Mises à jour système | ZIP autonome déployé en production sur `tests.yanntassy.fr`, fonctionnement confirmé par Yann ; préserver la base existante et vérifier les futures migrations sur une base isolée. Configuration manuelle. Assistant de configuration reporté. Serveur PHP et publication locale signée implémentés ; serveur déployé et publication vérifiée avec un ZIP réel, limites maximales d’upload du mutualisé restant à éprouver. Clé publique initiale et serveurs ordonnés fournis par le modèle, à reporter manuellement dans les configurations existantes. Consultation manuelle ROOT avec vérification des signatures, compatibilités, notes et simulation en DEV disponible. ZIP téléchargeables et contrôlés dans un cache privé réutilisable. Préparation ROOT avec extraction sécurisée, installation du moteur/assets et lien secret révocable disponible ; copies isolées en DEV. Réinitialisation ROOT des paquets, fichiers préparés et JSON disponible en DEV et production, avec conservation de la release active. Updater autonome avec actions séparées de préparation, maintenance, sauvegarde privée JSONL et SQL, migrations, activation et récupération ; nettoyage ROOT disponible. Restent à éprouver sur une base isolée le parcours réel depuis 1.0.0, les droits SQL des extensions, les limites HTTP et la coupure pendant sauvegarde/restauration. Le rappel global ROOT des fichiers temporaires, la durée de conservation, l’envoi facultatif du lien par e-mail, la rotation des clés et l’assistant de configuration restent à traiter. | [Fabrication](../../build/README.md), [Conception du chantier](CurrentWorks/MISES_A_JOUR_SYSTEME.md) |
| Publication et CI | Serveur de distribution : approbation manuelle en attente → bêta/prod et mini-UI d'administration livrés. Workflows `checks` et `release` livrés pour le cœur. Restent : canal bêta côté protocole et client (lots A2/A3), notifications Telegram/Slack/Discord, analyse des tickets GitHub, analyse différentielle `security_X`, CI des extensions et validation par canal dans TyCatalog. Le test `pageMediaComponents` signale un CSP média dupliqué sur les sources configurées. | [Chantier branches et CI](CurrentWorks/BRANCHES_ET_CI.md), [Serveur de distribution](../../update_server/README.md) |
| Extensions / LMS | Builds ESM et sélection d’une extension en HMR disponibles. ZIP de production et signatures automatiques par Store disponibles ; compatibilités déclarées dans version.json. Onglet Gestion réservé à ROOT : liste des extensions chargées, migrations Core en attente et désinstallation des extensions installées depuis un catalogue via le hook `uninstallExtension()`. Socle Core TyCatalog : administration des catalogues, publication des versions signées, rétention configurable des versions/ZIP, publication distante par jeton, API publique de distribution et client Core (sources, clés de confiance, licence, consultation et comparaison) disponibles ; installation neuve et mise à jour sécurisées disponibles ; conception et lots dans le [chantier TyCatalog](CurrentWorks/TYCATALOG.md). Compatibilités/dépendances et rotation des clés à traiter selon le chantier. Dépendances Composer propres aux extensions hors du premier contrat. LMS limité au squelette PHP/Vue et au canevas vide « Liste des cours », sans rendu public, route ni métier. | [Extensions](../EXTENSIONS.md) |
| Automatisation | Socle Core et lots 1-2 (`plg_automation`) : langage, interpréteur, built-ins purs, budgets, journal en mémoire et frontière de valeurs. Cœurs des lots 3-4, buffer d'événements et interface d'administration livrés : six tables, permissions, registres, `RunSession`, hook après commit, repository, exécuteur synchrone, WF manuel, persistance runs/steps, actions Core, notifications d'échec et de scénario chiffrées avec profils nommés, endpoint manuel, outbox `automation_events`, drain après commit, déduplication, émission de `core.user.created`, liste et éditeur de scénarios, vérification en lecture seule, publication sécurisée, historique des runs, catalogues serveur, référence Markdown téléchargeable, déclencheur bouton de page, API productrice `emit`/`triggerNow` avec `scenario-choices`, composant Vue du bouton avec modales standard et fonctions `res::*`, déclencheurs webhook bearer/query/HMAC avec réponse générique ou résultat, déclencheur planifié, génération/rotation des secrets, calculateur de créneaux et worker de planification exposé par le hook CRON core `onCronMinute` (`GET/POST /cron`, jeton `cron_token`), actions Google Sheets et Google Agenda avec compte de service chiffré par Space et permission `ADMIN` réservée à ROOT, maintenance manuelle (reprise, runs bloqués, relance, rétention configurable) avec tests d'intégration webhook/CRON/maintenance. Les actions de lecture de l'historique de page (`core::listPageHistory`, `core::readPageVersion`) sont livrées ; une action d'écriture de page reste à décider si la restauration directe par l'IA est confirmée. Restent l'exercice author/service, l'essai HelloAsso et l'audit de sécurité. Conception et lots dans le [chantier Automatisation](CurrentWorks/AUTOMATION.md). | [Chantier](CurrentWorks/AUTOMATION.md) |
| Éditeur de pages | Conteneurs libres et simulation d'autres visiteurs non exposés. Les documents antérieurs au v3 ne sont pas convertis automatiquement. | [Atelier](../PAGE_EDITOR.md), [Composants](../PAGE_COMPONENTS.md#document-json-v3) |
| Iframe | Domaines configurables par ROOT et affichés dans l’éditeur. Isolation sans allow-same-origin conservée : compatibilité Google Slides et besoin de permissions supplémentaires à vérifier séparément sur le site public. | [Iframe](../PAGE_EDITOR.md#iframe) |
| Couleurs des pages | Couleurs de template disponibles dans les propriétés, tous les TinyMCE et diagrams.net (Pages/Médias) ; opacité des variables non prise en charge dans les réglages de composants. Les aperçus utilisent le thème du Space courant. | [Couleurs de la template](../PAGE_EDITOR.md#couleurs-de-la-template) |
| Diagrammes de page | PNG/JPEG refusés à la validation ; le collage dans l’iframe externe n’est pas bloqué immédiatement. Polices distantes et SVG hors du sous-ensemble autorisé refusés. | [Diagrammes](../PAGE_EDITOR.md#diagrammes-diagramsnet) |
| Formulaires | Options dépendantes d'un autre champ non réévaluées automatiquement dans le formulaire déclaratif de composant de page ; utiliser un formulaire Vue spécialisé si nécessaire. | [Formulaires](../FORMS.md), [Composants](../PAGE_COMPONENTS.md#onglet-général--formulaire-php-ou-vue) |
| Multilangue | Titres et alias de menu localisés, correspondance entre pages équivalentes, sélecteur public de langue et liens `hreflang` à réaliser. Les textes de page localisés et le bootstrap Vue i18n sont disponibles. | [Langues](../LANGUAGES.md) |
| Traduction ORM | Métadonnées et projection explicite disponibles ; les modèles métier existants ne sont pas encore progressivement reclassés en champs traduisibles. | [Projection ORM](../LANGUAGES.md#projection-explicite-des-champs-orm) |
| Administration | Traduction de l'interface d'administration non réalisée ; elle reste en français. | [Langues](../LANGUAGES.md) |
| IA | Liste des modèles distants non chargée automatiquement ; formulaire de configuration IA spécifique, hors `FormRenderer`. | [IA](../AI.md) |
| Images IA | Pas d'enregistrement dans les médias, ni d'insertion automatique ; réponses image par URL seule refusées. | [Génération d'image](../AI.md#génération-dimage-commune) |
| SSO / OAuth | SAML et connecteurs vers des API externes précises non livrés. La déconnexion OAuth retire la copie locale, sans révocation distante. | [SSO](../SSO.md) |
| Journaux applicatifs | Rotation, quotas indépendants, capture des causes et consultation ROOT disponibles. Affichage et droits disque à confirmer avec Yann sur l’hébergement cible. Purge déclenchée par requête ; échéance stricte sans trafic à traiter par ordonnanceur externe. Audit métier et destinations externes hors du périmètre actuel. | [Journaux](../LOGGING.md) |
| Secrets | Rotation de `Configuration.secret` et rechiffrement des données à concevoir avant tout changement de clé. | [Chiffrement](../CRYPTO.md) |
| Inscription | Validation du compte par lien e-mail non livrée. | [Inscription](../REGISTRATION.md) |
| Sessions | Avec le stockage PHP, la récupération du mot de passe ne révoque que la session courante ; la révocation globale exige le stockage en base. | [Récupération](../PASSWORD_RESET.md#persistance-sécurité-et-sessions) |
| E-mails | SMTP synchrone, sans file transactionnelle ; un message peut être accepté avant le commit HTTP. Pied de page d'habillage commun aux langues d'un Space. | [E-mails](../MAIL.md) |
| HLS | Pas d'import ni de transcodage ; préparer les clés chiffrées avant publication pour éviter leur exposition statique initiale. Lecture volontairement publique, sans DRM. | [HLS](../MEDIAS.md#flux-vidéo-hls-par-lextension-core) |

## Validations manuelles utiles selon le chantier

Ces essais se font avec Yann lorsqu'une évolution touche leur parcours. Leur
présence ici ne signifie pas qu'aucun essai n'a jamais eu lieu.

- SMTP réel et affichage des e-mails dans les clients visés ; les tests PHP
  utilisent un transport de test sans connexion réelle.
- Fournisseurs IA réellement configurés, notamment génération d'image et
  suivi Infomaniak ; le contrat normalisé ne prouve pas la compatibilité d'un
  modèle distant précis.
- HLS sur les navigateurs visés, notamment Safari/iPhone, changements de source
  et clés `VKEY1` préparées avec les vrais mots de passe ; la lecture de
  l'exemple fourni a été confirmée, sans validation de tous ces cas.
- Connexion et retour OIDC réels, cookies et régénération de session pour les
  deux gestionnaires ; ne pas déduire ces résultats des seuls tests unitaires.
- Atelier : presse-papiers système, iframes API, raccourcis, glisser-déposer,
  sauvegarde/réouverture et rendu public. Réutiliser un plugin réel pour valider
  une extension métier ; le contrat générique n'est pas un test du futur LMS.
- HMR Vite lorsque ses montages ou sa configuration changent.

## Pistes à discuter avant réalisation

Ces idées sont conservées pour leur intérêt de conception, sans les présenter
comme des choix déjà arrêtés :

- **Images du chat vers les médias** : action explicite après génération,
  choix du Space et du nom, droit `MEDIA_CRUD`, contrôle des octets/MIME et
  stockage via `MediaHelper`. L'image affichée reste éphémère jusque-là.
- **Qualité des traductions** : tableau de complétude, détection des références
  `LG_` orphelines, pseudo-langue de développement, import/export avec aperçu
  des changements et contrôle des droits.
- **SEO multilangue** : identifier explicitement les pages équivalentes avant
  de fabriquer des liens de langue ; ne pas remplacer simplement `/fr/` par
  `/en/`. Une redirection permanente des anciennes URL demanderait une
  correspondance vérifiée ; le contrat actuel utilise une redirection 302.
- **Mon compte** : définir la réauthentification des actions sensibles. La
  protection du dernier moyen de connexion est en place ; seul le changement
  de mot de passe exige actuellement l'ancien secret.
- **Validation des e-mails** : définir une option de Space pour vérifier
  l'adresse à l'inscription et lors de son changement depuis Mon compte.
  Le changement d'adresse s'applique actuellement sans confirmation.
- **Orchestration IA parallèle** : seulement après besoin constaté ; définir
  droits, budgets, conflits de brouillon et confirmation commune auparavant.

Les idées produit supplémentaires restent dans les
[notes de Yann](../TODO_NOTES_YANN.md), sans les transformer en contrats livrés.
