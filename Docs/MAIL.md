# Envoi d’e-mails

## Modèles d'e-mail métier

Les extensions peuvent déclarer des `EmailTemplateDefinition` par
`IEmailTemplateProvider::emailTemplates()`. Chaque définition possède un ID
local stable, des variantes par langue, la liste des variables `%nom%`, un
mode de mise en page (`default` ou `blank`) et le booléen `customizable`
(désactivé par défaut). Le registre ajoute le préfixe de l'extension, par
exemple `plg_core__registration_welcome`. Les modèles Core sont regroupés dans
`Core/Mail/CoreEmailTemplates.php` : `password_reset` (récupération publique),
`registration_welcome` (inscription locale), `registration_sso_welcome`
(première création de compte SSO) et `admin_account_welcome` (création par
l'administration avec mot de passe initial). Ils sont consultables et
personnalisables. Le catalogue expose tous les modèles déclarés sans statut
d'utilisation dans leur définition, les réponses de l'API ou l'interface.

`MailHelper::sendTemplate(key, to, values, sourceSpace, locale)` résout le
modèle dans le Space source, remplace les variables une seule fois, applique
son habillage et utilise le SMTP existant. Il accepte aussi les copies,
l'adresse de réponse et les pièces jointes de `sendHtml()`. `locale` peut être omise : la langue
par défaut active du Space source est alors prise. Une langue passée
explicitement doit être active dans ce Space. Pour la langue demandée, une
surcharge du Space prime sur celle du parent le plus proche, puis sur la
version du code ; si le code ne fournit pas cette langue, sa version française
sert de repli. Une surcharge remplace ensemble l'objet et le contenu. Si
`customizable` est faux, les lignes stockées sont ignorées.

La table `email_template_overrides` stocke les surcharges par
`(space_id, template_key, locale)`. L'administration « Modèles d'e-mails »
permet de les éditer, de les prévisualiser et de restaurer l'héritage avec la
permission `EMAIL_TEMPLATE_EDIT` sur le Space concerné. Le contenu s'édite
dans deux modes au choix : texte riche ou CodeMirror HTML ; les deux
manipulent le même brouillon. Les variables, y compris celles de type
`url` et `https_url`, peuvent apparaître dans l'objet, le texte et les attributs HTML
conservés par le nettoyeur. Le type `url`, utilisé par `%reset_url%`, accepte
HTTP et HTTPS ; `https_url` conserve son exigence HTTPS. Les URL excluent les identifiants,
et les valeurs sont échappées selon leur contexte. Aucun lien cliquable n'est
obligatoire ; l'option `requiredInLink` est supprimée de `EmailTemplateVariable`
et des métadonnées de l'API. L'HTML administrable est nettoyé avec un
profil e-mail dédié. Les images doivent être des médias publics existants du
Space propriétaire de la surcharge. L'aperçu utilise des valeurs fictives.
Une variable peut être interdite dans l'objet (`allowedInSubject: false`) ou
obligatoire dans le contenu (`requiredInBody: true`). Le mot de passe initial
du modèle administratif applique ces deux restrictions, y compris aux
surcharges.

`UserWelcomeMailHelper` envoie les trois messages de bienvenue avec le Space
d'inscription ou de création, et sa langue active ou la langue par défaut du
Space. Une adhésion à un autre Space et une connexion SSO d'un compte existant
ne déclenchent pas de bienvenue. Si l'envoi échoue, le compte est conservé et
un avertissement générique est placé dans `PendingMessageQueue`. La création
administrative renvoie aussi `welcome_email_sent` (`true`, `false` ou `null`
si l'option n'a pas été demandée). Le mot de passe initial ne figure que dans
le corps du modèle administratif : ne pas le mettre dans l'objet, les journaux
ou les messages d'erreur. L'envoi suit les transactions déjà en place dans les
parcours ; il n'y a pas de file d'envoi différé.

Le canal `mail` conserve les causes techniques absorbées lors d'une bienvenue
(`WELCOME_MAIL_FAILED`, ERROR), ou signale le retour `false` du transport
(`WELCOME_MAIL_NOT_SENT`, WARNING, y compris si l'envoi est désactivé). Le
contexte contient le Space et la clé du modèle contrôlée par le code dans
`kind`, sans destinataire, variables de rendu ni mot de passe initial.
Les essais administratifs des réglages SMTP et de l'habillage utilisent
respectivement `SMTP_TEST_FAILED` / `SMTP_TEST_NOT_SENT` et
`TEMPLATE_TEST_MAIL_FAILED` / `TEMPLATE_TEST_MAIL_NOT_SENT`, avec la même
distinction exception/retour `false`. Le traitement public des erreurs reste
inchangé ; le runner ne duplique pas une cause déjà capturée. Voir [Journaux](LOGGING.md).

Le parcours de récupération du mot de passe utilise le modèle `password_reset`
avec une URL produite par le code. Voir `Docs/PASSWORD_RESET.md` pour ses règles,
sa configuration et la limitation des demandes.

### Déclarer et envoyer un modèle depuis une extension

Implémenter `Core\ExtensionApi\Mail\IEmailTemplateProvider` sur le point
d'entrée de l'extension. Ce contrat optionnel est distinct de `IExtensionEvents`.

```php
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Mail\EmailTemplateDefinition;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Mail\EmailTemplateVariable;

public function emailTemplates(): array
{
    return [new EmailTemplateDefinition(
        id: 'course_welcome',
        label: 'Bienvenue dans un cours',
        description: 'Message envoyé après une inscription au cours.',
        customizable: true,
        renderMode: 'default',
        variants: [
            'fr' => [
                'subject' => 'Bienvenue %first_name%',
                'bodyHtml' => '<p>Bonjour %first_name%, votre inscription est enregistrée.</p>',
            ],
        ],
        variables: [new EmailTemplateVariable('first_name', example: 'Alice')],
    )];
}
```

Le registre contrôle les identifiants et les collisions au chargement. `id`
et les noms de variables suivent `[a-z][a-z0-9_]*` ; le registre préfixe l'ID
avec le propriétaire réel. La clé complète est limitée à 150 caractères.
`label`, `description` et une variante `fr` sont à fournir au constructeur.
Les modes de modèle sont `default` et `blank` ; `raw` est réservé à l'envoi
direct. Les définitions et variables appartiennent au code, sans création
libre dans l'UI. Exemple d'envoi depuis le métier de `plg_lms` :

```php
$sent = $this->coreMailHelper->sendTemplate(
    key: 'plg_lms__course_welcome',
    to: $user->email,
    values: ['first_name' => $user->first_name],
    sourceSpace: $space->id,
    locale: 'fr',
);
```

Le WF appelant autorise le destinataire, le Space source et l'action métier.
L'accès partagé `coreEmailTemplateHelper` expose aussi `describe()` et
`prepare()` ; il ne remplace pas l'ACL du WF. `describe()` distingue les sources
`space`, `parent`, `code` et `code_fr_fallback`, avec `sourceSpaceId`,
`effectiveLocale` et la surcharge locale. `prepare()` fournit l'objet, le corps,
le mode et la langue effective. Celle-ci arrive à `EmailRenderContext` et au
`html[lang]` de MainTemplate ; le pied de page reste commun aux langues du Space.

### Règles à préserver

- La langue doit être active dans le Space source de l'envoi. Une surcharge
  d'un parent reste héritable même si sa langue y est désactivée. Sans surcharge
  `en`, une surcharge `fr` ne s'applique pas : le repli français vise le code.
- Un modèle devenu incompatible avec ses variables bloque l'envoi avant SMTP,
  sans repli silencieux. Une définition retirée devient indisponible ; renommer
  son ID ne reprend pas ses surcharges sans migration explicite.
- `EmailTemplateVariable` accepte `text`, `url`, `https_url`, avec `required`
  (`true` par défaut), `example`, `allowedInSubject`, `requiredInBody`.
  Chaque valeur est limitée à 8 192 octets UTF-8, substituée une seule fois.
- `EmailTemplateContent` limite l'objet à 255 octets sans contrôle ni retour
  à la ligne, et le corps à 131 072 octets UTF-8. Son profil HTML e-mail est
  distinct de celui des pages, revalidé avant rendu ; scripts, iframes, vidéos,
  formulaires et attributs dangereux sont exclus.
- Un marqueur URL occupant un `href` entier est protégé pendant le nettoyage,
  puis validé et échappé à la substitution. Ne pas élargir aveuglément les
  attributs autorisés pour garder un marqueur. Une variable retirée par le
  nettoyage rend le modèle invalide.
- L'aperçu utilise des exemples, jamais un vrai jeton ou mot de passe. Son
  iframe reste sandboxée sans scripts ni même origine. `EMAIL_TEMPLATE_EDIT`
  permet de changer le message et ses liens : le réserver aux administrateurs
  de confiance.

### API des modèles

Routes sous `/administrator/api/email-templates`, déléguées à `WFEmailTemplate` :

| Méthode / chemin | Usage |
| --- | --- |
| `GET spaces` | Spaces autorisés et langues. |
| `GET /` | Liste et provenance pour `spaceId`, `locale`. |
| `GET detail` | Définition et valeurs pour `spaceId`, `locale`, `key`. |
| `POST preview` | Aperçu du brouillon, sans sauvegarde. |
| `POST save` | Surcharge complète du couple objet/contenu. |
| `POST restore` | Retrait de la surcharge locale pour restaurer l'héritage. |

Le WF contrôle le Space, `EMAIL_TEMPLATE_EDIT`, la langue, le modèle connu et
`customizable` avant l'écriture. Les POST utilisent CSRF et transaction de
route. Détail et aperçu portent `Cache-Control: private, no-store`.

## Envoi direct et mise en page

`Core\Helper\Mail\MailHelper` envoie immédiatement des messages HTML par SMTP avec
PHPMailer. Les extensions y accèdent par la propriété typée `$this->coreMailHelper`
de `TraitCoreSharedHelpers` (également composée par `TraitAllHelpers`).
`sendHtml()` résout le Space source, applique par défaut l'habillage e-mail de
sa Template, puis délègue l’envoi à une méthode protégée qui accepte des
paramètres SMTP explicites. Cette dernière sert à l’essai d’administration
avec les valeurs non enregistrées du formulaire, en HTML brut.

```php
$sent = $this->coreMailHelper->sendHtml(
    to: ['alice@example.test' => 'Alice', 'bob@example.test'],
    subject: 'Bienvenue',
    html: '<p>Bonjour !</p>',
    sourceSpace: $space->id, // facultatif : null utilise le Space courant
    cc: 'copie@example.test',
    bcc: ['archive@example.test'],
    replyTo: 'reponse@example.test',
    attachments: [
        '/chemin/vers/document.pdf',
        ['path' => '/chemin/vers/image.png', 'name' => 'visuel.png'],
    ],
    renderMode: 'default', // défaut ; 'blank' ou 'raw' également possibles
);
```

`to`, `cc`, `bcc` et `replyTo` acceptent une adresse, une liste d’adresses ou
un tableau `adresse => nom`. `to` exige au moins une adresse. `sourceSpace`
accepte un objet `Space`, son ID, ou `null` pour le Space courant. Un ID explicite
inconnu provoque une exception. Le helper n’applique pas d’ACL : le workflow
appelant doit autoriser le Space source et l’accès aux fichiers joints.

`renderMode: 'default'` encadre le fragment HTML dans la Template du Space
source : logo, contenu et pied de page. `blank` utilise la même Template sans
logo ni cadre ; `raw` transmet le HTML fourni tel quel. Les méthodes
`sendDefaultHtml()`, `sendBlankHtml()` et `sendRawHtml()` exposent ces choix
directement, avec les mêmes autres paramètres que `sendHtml()`. Un document HTML
complet fourni par l'appelant peut utiliser `raw` ; les deux autres modes
attendent le HTML du contenu à encapsuler.

La Template est toujours celle déclarée par `spaces.template` du Space source,
et sa configuration est lue pour ce même Space, comme dans
`BaseController::renderAndGetTemplateResponse()`. Chaque classe `BaseTemplate`
doit implémenter `renderEmail(string $content, object $templateParams, string
$mode, EmailRenderContext $context): string`. Les modes transmis aux Templates
sont `blank` et `default`. `EmailRenderContext` fournit le nom du Space et son
origine publique. Les chemins locaux du logo et du pied de page deviennent des
URL absolues `https://<main_host>/...`. L'absence ou l'invalidité de
`spaces.params.main_host` fait échouer un envoi avec Template avant toute
connexion SMTP ; le mode `raw` n'en dépend pas.
En mode développement (`Configuration.dev = true`), `localhost:<port>` est aussi
accepté pour le domaine principal et les hôtes à l'enregistrement du Space,
avec un port de 1 à 65535. Le Space conserve cette valeur sans protocole ; les
liens des e-mails utilisent alors `http://localhost:<port>/...`. Cette exception
ne dépend pas de `DOCKER_HTTP_PORT` et reste désactivée en production.
Les erreurs de configuration ou de domaine remontent comme
`ApplicationException`.

La Template MainTemplate configure les deux habillages par Space. Le mode `default`
expose le logo, ses dimensions maximales, les fonds autour et dans le cadre,
sa largeur et ses espacements, le séparateur et les couleurs du pied de page.
Le texte du pied de page remplace `{space}` et `{year}` à l'envoi. Son lien
accepte un chemin local ou une URL HTTPS ; un libellé vide affiche l'URL. Le
mode `blank` expose sa couleur de fond. Les deux partagent les polices sûres
pour l'e-mail (Arial, Georgia, Verdana, Tahoma, Trebuchet), les tailles de texte
et de titres, l'interligne, ainsi que les couleurs du texte, des titres et des
liens. L'habillage utilise des tables et des styles de présentation inline ;
les styles des titres et liens sont également déclarés dans le document HTML.
Le HTML fourni par l'appelant reste volontairement libre et doit être de
confiance.

`Core\Helper\Mail\MailAdminHelper` porte `protectSmtpPassword()` et
`sendTestHtml()`. Son accès typé `$this->coreMailAdminHelper` est déclaré dans
`TraitCoreAdditionalHelpers`, réservé au code interne de Core ; il ne figure
pas dans `TraitCoreSharedHelpers` exposé aux extensions. L’API
`POST /administrator/api/spaces/mail/test` accepte un Space existant, un
destinataire et les réglages SMTP du formulaire. `WFSpace` recharge le Space,
exige `SPACE_EDIT` ou `SPACE_ADMIN` sur celui-ci, valide le destinataire et
refuse l’essai si `mail_online` est désactivé. Le mot de passe saisi remplace
temporairement celui enregistré ; un champ vide utilise le mot de passe
enregistré. Aucun réglage n’est sauvegardé. L’interface d’édition seule
propose cet essai ; la création d’un Space doit d’abord être enregistrée.

La Template MainTemplate expose `POST /administrator/api/templates/main/mail/test` depuis
ses onglets e-mail « cadre » et « simple ». La route est déclarée par son
contrôleur, puis déléguée à `WFExtensions`, qui exige `TEMPLATE_EDIT` sur le
Space ciblé et vérifie que `tpl_main` lui est attribuée. Elle accepte le
destinataire, le mode et le brouillon complet du formulaire ; ce brouillon est
validé par la définition serveur, puis rendu sans être enregistré. Les médias
sélectionnés sont contrôlés dans le Space, sans exiger un second droit en plus
de `TEMPLATE_EDIT` pour cet essai. L’envoi utilise les réglages SMTP déjà
enregistrés du Space. Le champ destinataire est prérempli avec `mail_from` et
reste modifiable. Si le domaine principal est absent, une alerte apparaît dans
les deux onglets e-mail et les boutons de test sont désactivés. Les API
vérifient aussi `mailonline`, `mail_online`, le destinataire et le domaine avant
toute connexion SMTP. Le succès indique seulement que le serveur SMTP a
accepté le message.

`Configuration.mailonline` est un interrupteur global prioritaire sur les
paramètres de tous les Spaces, y compris les réglages non enregistrés de
l’essai. Quand il vaut `false`, `sendHtml()` retourne `false` sans envoi,
comme lorsque `mail_online` est désactivé. L’API d’essai renvoie le message
« L’envoi d’e-mails est désactivé sur ce site. » après le
contrôle des droits. Ce réglage n’est pas affiché dans l’éditeur de Space.

Les paramètres sont lus dans `spaces.params` : `mail_online`, `mailer`,
`mail_from`, `mail_from_name`, `mail_smtp_host`, `mail_smtp_port`,
`mail_smtp_auth`, `mail_smtp_user`, `mail_smtp_pass` et `mail_smtp_secure`.
Le transport disponible est `smtp` ; `ssl` active SMTPS, `tls` active STARTTLS
et la valeur vide demande une connexion non chiffrée. Le formulaire d’édition
des Spaces expose déjà ces réglages dans sa section SMTP. Un nouveau mot de
passe est chiffré avec `CryptHelper` dans `spaces.params.mail_smtp_pass` ;
`mail_smtp_pass_key_version` vaut alors `1`. La migration initiale des Spaces
et leurs paramètres par défaut initialisent cette version à `0`. Un mot de
passe précédemment stocké en clair reste lisible jusqu’à la prochaine
modification du Space, qui le chiffre même si le champ de saisie reste vide.
Sur une base déjà initialisée, la migration initiale ne se rejoue pas ; une
version absente est donc traitée comme `0`.
Un mot de passe déjà chiffré est conservé lors d’une édition qui laisse ce champ
vide. Le délai de connexion SMTP est limité à 4 secondes.

`sendHtml()` retourne `false` sans ouvrir de connexion si `mailonline` ou
`mail_online` est désactivé. Sinon il retourne le résultat de PHPMailer ; une
configuration invalide ou un échec de transport lève une exception. La réussite signifie que
le serveur SMTP a accepté le message, pas qu’il est arrivé en boîte de réception.
Une version texte est déduite du HTML pour les clients qui n’affichent pas le HTML.
L’envoi est synchrone : il peut avoir lieu avant la validation de la transaction
HTTP qui l’a déclenché.

Le rendu e-mail est testé sans serveur SMTP réel. Aucun test SMTP réel n’est
inclus dans la suite unitaire. Voir `Docs/CRYPTO.md` pour le format chiffré et
les exigences concernant `Configuration.secret`.

Le réglage `logo_email` appartient à la configuration par Space de la Template
MainTemplate. Il utilise `core.media_file` en mode image, dans sa section e-mail
`default` ; il ne fait plus partie de `spaces.params`.
