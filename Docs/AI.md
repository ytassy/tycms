# API d'assistance IA (Core)

## Configuration et droits

La migration `Mig_1_0_0_p5.php`, enregistrée pour Core 1.0.1, crée
`ai_providers` et la permission `AI_USE`. Chaque connexion appartient à un
Space et contient son type, son URL, son `product_id` éventuel, son état et sa
clé API chiffrée. Une clé absente lors d'une modification conserve la clé déjà
enregistrée. L'API d'administration ne renvoie jamais le secret.

`spaces.params.ai_models` associe chacun des usages `decision`,
`proofreading`, `design` et `image_generation` à un `provider_id` et un nom de
modèle. Le fournisseur doit appartenir au même Space. `SPACE_ADMIN` permet de
configurer les connexions et les modèles ; `AI_USE` permet d'exécuter une
opération dans ce Space. Core 1.0.2 ajoute `AI_GENERATE_IMAGE`, attribué au rôle
ROOT par `Mig_1_0_2.php`. La génération d'image exige les deux permissions.
Le workflow revérifie les droits et le fournisseur à chaque appel, y compris
lors du suivi d'un batch image.

## Diagnostiquer les erreurs

Dans les journaux ROOT, filtrer le canal `ai`. `WFAi` fixe, après contrôle des
droits, `space_id`, `provider_id`, `source` (type du fournisseur), `kind`
(`llm`, `image` ou `decision`) et `mode` (`run` ou `poll`). La référence de
requête permet de rapprocher ces diagnostics des autres événements.

| Code | Diagnostic |
| --- | --- |
| `AI_DNS_FAILED`, `AI_ADDRESS_REFUSED` | Résolution impossible ou adresse non publique refusée par la protection réseau. |
| `AI_CONFIGURATION_INVALID`, `AI_OPERATION_UNSUPPORTED`, `AI_REQUEST_INIT_FAILED` | Configuration ou opération non prise en charge, préparation de la requête impossible. |
| `AI_TIMEOUT`, `AI_CONNECTION_FAILED` | Délai dépassé ou échec réseau/TLS ; `curl_errno` donne le code numérique, sans le texte cURL. |
| `AI_HTTP_ERROR` | Refus du fournisseur avec `http_status`. Pour certains codes connus : clé refusée, quota épuisé, débit limité, modèle introuvable/inaccessible, contexte trop long ou refus de contenu. |
| `AI_RESPONSE_TOO_LARGE`, `AI_JSON_INVALID`, `AI_RESPONSE_INVALID` | Réponse trop volumineuse, JSON incorrect ou structure inattendue. |
| `AI_IMAGE_INVALID`, `AI_IMAGE_TOO_LARGE` | Image absente, URL au lieu du base64 attendu, format/dimensions invalides ou taille excessive ; le libellé contrôlé précise le cas. |
| `AI_BATCH_ID_INVALID`, `AI_BATCH_STATUS_INVALID`, `AI_BATCH_FAILED` | Identifiant ou statut inattendu, ou traitement distant annoncé en échec/annulé. |

Ces erreurs techniques sont en ERROR, sauf `AI_BATCH_FAILED` en WARNING. Les
causes internes et localisations sont conservées selon [le contrat des logs](LOGGING.md).
Le diagnostic ne stocke ni clé, ni modèle, ni prompt, conversation, image,
identifiant de batch, URL ou réponse brute. Les messages distants ne sont pas
recopiés : seuls les codes reconnus sont traduits en libellés fixes ; sinon,
le diagnostic HTTP reste générique. Le message public existant est conservé.
Les validations ordinaires et le suivi d'un batch encore en attente ne créent
pas d'entrée supplémentaire.

## Points d'entrée

Les routes publiques ci-dessous utilisent la session et, pour les requêtes
`POST`, le jeton CSRF habituel. Elles appellent `WFAi` et ne maintiennent pas de
transaction SQL pendant la requête au fournisseur.

| Route | Paramètres | Résultat |
| --- | --- | --- |
| `GET /api/ai/capabilities` | `spaceId` | `imageGeneration: bool` pour l'utilisateur courant et le Space ; exige `AI_USE` |
| `POST /api/ai/llm` | `spaceId`, `useCase` (`proofreading` ou `design`), `input` avec `messages`, `tools` et `max_tokens` facultatifs | message assistant et usage |
| `POST /api/ai/decision` | `spaceId`, `input` avec `state` et `questions` typées | réponses et usage |
| `POST /api/ai/image` | `spaceId`, `input` avec `prompt` | images et usage ; exige aussi `AI_GENERATE_IMAGE` |
| `POST /api/ai/status` | `batchId` | état du batch ; revérifie `AI_GENERATE_IMAGE` pour les batches image |

Les Tools LLM utilisent le format OpenAI `type: function`, avec `name`,
`parameters` et une description facultative. Le client Vue reste responsable
de l'exécution de ces Tools et de l'application de leurs changements. Pour
Anthropic, le backend adapte ce format à l'API Messages.

Les limites d'entrée sont appliquées dans `WFAi::validateInput()` :

| Opération | Limites serveur |
| --- | --- |
| LLM | 100 messages, 30 définitions de Tools, `max_tokens` de 1 à 8 192 si fourni, payload JSON de 300 000 octets au plus. |
| Decision | `state` et `questions` objets JSON, 20 questions au plus, payload de 100 000 octets. |
| Image | Prompt non vide de 4 000 octets au plus ; la limite fournisseur, notamment Infomaniak, peut être plus petite. |

Le budget local de tours de Tools ne remplace pas ces limites ni celles du
modèle distant. Garder les lectures de contenu ciblées et la réduction du
contexte avant de les atteindre.

## Chat Vue et Tools locaux

Le composant global `<ai_chat>` se trouve dans
`src/ria/src/components/common/ai/ai_chat.vue`. Il reçoit `space-id`,
`use-case` (`proofreading` ou `design`), `tools`, et éventuellement `title`,
`system-prompt`, `initial-message`, `initial-draft`, `closable` et `embedded`.
`initial-message` lance le premier prompt au montage ; `initial-draft` préremplit
la saisie sans lancer de requête. Le mode `embedded`
masque l'en-tête et la carte du chat lorsqu'un conteneur fournit déjà ces éléments.
Le chat occupe toute la largeur disponible ; son conteneur fixe la largeur voulue.
Le chat garde sa conversation tant qu'il est monté. Le store `useAiChatStore`
appelle les routes LLM et de suivi ;
le chat attend les batches du fournisseur, traite les `tool_calls`, puis transmet
les résultats au modèle. Une conversation est limitée à six tours de Tools et
le suivi d'un batch à 7 min 30 s ; l'utilisateur peut arrêter l'attente.
Dans la zone de saisie, Entrée envoie le message et Maj+Entrée ajoute une ligne.
L'envoi par Entrée est ignoré pendant la composition d'un caractère au clavier.

Les réponses textuelles de l'IA sont affichées en Markdown (titres, listes,
liens, tableaux et blocs de code). Le HTML produit par le parseur est nettoyé
avant insertion dans le DOM ; les images et les styles HTML fournis par le modèle
sont exclus. Les messages de l'utilisateur restent affichés en texte échappé.
Les titres, listes et citations Markdown ont une typographie propre au chat qui
remplace les styles globaux de titre et de citation des pages.
Les tableaux ont des cellules lisibles, un en-tête distinct et un défilement
horizontal lorsque leur largeur dépasse celle du chat.
L'historique défile vers le dernier élément dès l'envoi d'un message et lors des
réponses, propositions de modification et changements d'état. Un indicateur
animé signale l'attente, avec animation désactivée si le navigateur demande une
réduction des mouvements.

Chaque Tool est un objet JavaScript local :

```js
{
  name: 'replace_content',                 // nom unique, format OpenAI
  description: 'Remplacer le contenu',    // visible par le modèle
  parameters: { type: 'object', properties: { html: { type: 'string' } }, required: ['html'] },
  mode: 'review',                          // 'direct', 'review' ou 'approval'
  validate: args => { /* lever une erreur si nécessaire */ },
  preview: args => ({ before: currentHtml, after: args.html, format: 'html' }),
  execute: args => { /* modifier le composant appelant */ return { applied: true } },
}
```

Seuls `name`, `description` et `parameters` partent vers le fournisseur. Le chat
rejette les noms inconnus et les arguments JSON invalides avant d'appeler une
fonction locale. `validate` est facultatif et doit lever une erreur lorsque les
arguments ne conviennent pas. `mode: 'direct'` exécute immédiatement ;
`mode: 'review'` appelle `preview`, montre les mots ajoutés et supprimés, puis
exécute seulement après validation explicite. Une `preview` HTML montre aussi
le code exact avant et après, échappé dans l'interface. Le composant appelant
choisit le mode dans son code ; le modèle ne peut pas le changer.
`mode: 'approval'` appelle `approval(args)` pour montrer un libellé et un résumé
avant une opération, puis exécute uniquement après confirmation. Il sert aux
appels facturables qui n'ont pas encore de résultat à comparer.

Le workflow PHP conserve les objets du schéma JSON, notamment
`parameters.properties: {}` pour un Tool sans argument. Une conversion en tableau
PHP produirait `[]`, refusé par certaines API compatibles OpenAI.

`createHtmlContentTools({ read, write, canWrite, mode })` fournit les Tools
réutilisables `get_content` et `replace_content`. Le second exige une lecture
préalable et refuse d'écraser le champ si celui-ci a changé depuis cette lecture.
`RichTextEditor` l'utilise avec `mode: 'review'` : le bouton « Corriger avec
l'IA » n'apparaît qu'avec `AI_USE` dans le Space du champ. Le chat de correction
est affiché dans le composant commun `Modal`, avec `embedded` pour éviter un
second en-tête et une seconde carte. À l'ouverture, le prompt de correction est
prérempli dans la saisie ; l'utilisateur décide quand l'envoyer. L'IA peut proposer
le HTML complet, y compris la structure et la mise en forme ; l'application
finale passe par TinyMCE et son historique d'annulation. L'enregistrement et
la validation du contenu de page suivent ensuite leur parcours habituel.

Pour un autre éditeur, le composant appelant peut fournir ses propres Tools
avec le même contrat, par exemple une action de lecture des composants de page
et une action d'édition avec `mode: 'review'`.

### Génération d'image commune

Chaque instance de `<ai_chat>` interroge les capacités du Space avant chaque
demande. Elle ajoute le Tool partagé `generate_image` aux Tools de son écran
uniquement si `AI_USE` et `AI_GENERATE_IMAGE` sont accordés, qu'un modèle image
est affecté et que son fournisseur est actif et compatible. Le prompt système
est actualisé selon cette disponibilité. Le Tool reçoit seulement un prompt, affiché pour confirmation avant
l'appel à `POST /api/ai/image`. Une demande utilisateur peut déclencher au plus
une génération. Le serveur contrôle encore ces conditions lors de l'appel ;
`MEDIA_CRUD` n'est pas requis tant que l'image n'est pas
enregistrée. Un prompt Infomaniak est limité à 400 caractères, selon son API.

Le serveur ramène les réponses immédiates et différées au même format
`result.images[0] = { b64_json, media_type }`. Il accepte une image PNG, JPEG ou
WebP en base64, jusqu'à 10 Mo et 8192 pixels par dimension. Les réponses
contenant seulement une URL sont refusées. La limite HTTP de 20 Mo est appliquée
pendant la réception, avant le décodage JSON. Le chat affiche l'image par une
URL `data:` autorisée par la CSP. Seul un résultat textuel court est transmis
au modèle : le base64 reste hors de l'historique LLM.

L'image reste dans le chat courant, sans être insérée dans une page ni dans les
médias ; elle disparaît quand le chat est démonté ou rechargé. Arrêter une
demande empêche l'affichage d'un résultat tardif, mais n'annule pas un appel
déjà accepté par le fournisseur. L'upload média est une étape distincte.

### Assistance dans l'éditeur de pages

L'onglet IA du panneau gauche est disponible pour les pages visuelles modifiables
avec `PAGE_CRUD` et `AI_USE` dans leur Space. Les Tools et leur intégration sont
dans `src/ria/src/components/administrator/pages/ai/`. Le chat commun reste
dans `src/ria/src/components/common/ai/`. La conversation reste montée lors
d'un changement d'onglet et est réinitialisée à l'ouverture d'une autre page ou
au changement de Space.

Les Tools lisent le plan paginé, un nœud par ID, un segment de long contenu HTML
ou le catalogue des types. Ils proposent séparément l'ajout d'un composant ou
d'une colonne, le déplacement, la modification de champs déclarés, le
remplacement d'un fragment HTML, les largeurs de colonne, les styles communs et
la suppression. Les types d'extension viennent du catalogue serveur ; seuls
leurs `editorFields` modifiables sont accessibles par le Tool générique. Une
extension complète la validation par `PageComponent::validateData()` et
`validateNode()`, en complément de son schéma et de son formulaire.
Les `editorFields` marqués `localized: true` sont exclus de ce Tool générique :
la modification d'une chaîne isolée ne doit pas écraser l'objet des variantes.

Une lecture du brouillon est exigée avant toute proposition. La proposition
agit sur une copie, vérifie que le brouillon n'a pas changé depuis la lecture,
puis appelle `POST /administrator/api/pages/ai/validate-draft`. Cette route
normalise et valide le document sans le rendre ni l'enregistrer ; le WF vérifie
les droits sur la page réelle. Le HTML produit par l'IA pour `core.html` et
`core.rich-text` passe d'abord par
`POST /administrator/api/pages/ai/sanitize-html`, qui réutilise le sanitizer PHP
et renvoie le HTML admis. La confirmation affiche les données avant et après.
Après acceptation, le document est appliqué au seul brouillon. Le bouton
« Enregistrer » reste la seule écriture de la page.

`ai_chat` accepte `max-tool-rounds` et `max-tool-calls`. Les valeurs par défaut
gardent six tours et quarante appels ; l'éditeur de pages demande quinze tours
et quarante appels par envoi. Une réponse peut appeler plusieurs Tools. Le chat
arrête une lecture identique répétée trois fois sans progression. Au-delà de
160 Ko ou de 70 messages, il abrège le contexte transmis au fournisseur en
conservant la demande actuelle et les derniers échanges complets ; l'historique
visible reste présent. Si le fournisseur signale encore une limite de contexte,
un second essai utilise un budget de 60 Ko et 30 messages. Les Tools relisent
toujours le brouillon réel, indépendamment de cet historique abrégé.

Une réponse immédiate a la forme `status: completed` avec `result`. Si le
fournisseur renvoie un identifiant de batch, la réponse est `status: pending`
avec un `batchId` opaque. Le client conserve cet identifiant et interroge
`/api/ai/status`. Le jeton chiffré contient le fournisseur, le Space,
l'utilisateur, l'opération et l'identifiant natif ; il expire après sept jours.
Le serveur ne crée pas de table de batches. Le suivi natif est actuellement
implémenté pour Infomaniak ; sa réponse finale contient la donnée retournée
par son API, qui peut varier selon le type de traitement.

L'administration utilise `GET /administrator/api/ai/configuration` avec
`spaceId`, puis les routes `POST /administrator/api/ai/providers/create`,
`providers/update`, `providers/delete` et `models/save` sous le même préfixe.
La page « Assistance IA » de l'administration liste les Spaces sur lesquels
l'utilisateur possède `SPACE_ADMIN`. Pour l'espace sélectionné, elle permet de
créer, modifier, désactiver et supprimer les connexions, puis d'affecter un
fournisseur et un identifiant de modèle à chaque usage. L'identifiant du modèle
se saisit librement ; la liste des modèles distants n'est pas encore chargée
automatiquement. Une clé laissée vide lors d'une modification est conservée.
Cet écran utilise les schémas JSON des routes IA et un formulaire Vue propre à
la fonctionnalité ; il ne suit pas encore le parcours `FormRenderer` décrit
dans `Docs/FORMS.md` pour les autres formulaires Core.

## Adaptateurs et routes des fournisseurs

Le backend prend en charge OpenAI, une API compatible OpenAI, OpenRouter,
Infomaniak et Anthropic. L'API Decision typée JEV passe par la route
`/api/alpha/decisions` d'OpenRouter. Une connexion Infomaniak enregistre
`https://api.infomaniak.com` et `config.product_id` ; l'ancienne valeur
`https://api.infomaniak.com/1/ai` reste acceptée. Les routes sont choisies
selon l'opération :

Pour OpenRouter, le LLM appelle `POST https://openrouter.ai/api/v1/chat/completions`
avec le modèle, les messages et les Tools éventuels. La génération d'image
appelle `POST https://openrouter.ai/api/v1/images` avec `model` et `prompt` ;
le modèle choisi doit être disponible dans le [catalogue Image API](https://openrouter.ai/docs/api/api-reference/images/list-image-models).
Les réponses d'erreur HTTP exposent au client le court message `error.message`
renvoyé par le fournisseur, lorsqu'il est présent, pour diagnostiquer un modèle
ou un payload refusé.

| Opération Infomaniak | Route du fournisseur |
| --- | --- |
| Chat LLM, Tools selon le modèle | `POST /2/ai/{product_id}/openai/v1/chat/completions` |
| Génération d'image | `POST /1/ai/{product_id}/openai/images/generations` |
| Suivi du batch | `GET /1/ai/{product_id}/results/{batch_id}` |

La [route de chat v1](https://developer.infomaniak.com/docs/api/post/1/ai/%7Bproduct_id%7D/openai/chat/completions)
est obsolète ; la [route v2](https://developer.infomaniak.com/docs/api/post/2/ai/%7Bproduct_id%7D/openai/v1/chat/completions)
documente notamment les Tools. L'[image](https://developer.infomaniak.com/docs/api/post/1/ai/%7Bproduct_id%7D/openai/images/generations)
et le [suivi du batch](https://developer.infomaniak.com/docs/api/get/1/ai/%7Bproduct_id%7D/results/%7Bbatch_id%7D)
restent documentés en v1.

Le transport HTTP impose HTTPS, refuse les redirections et les adresses IP
non publiques, et borne la durée et la taille des réponses. Le parcours
OpenRouter LLM avec Tools a été essayé dans le chat Vue après correction du
schéma JSON. Les autres fournisseurs restent à valider en conditions réelles.
L'écriture des images générées dans les médias reste à intégrer.
