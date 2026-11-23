# Politique de sécurité

TyCMS est un CMS destiné à des hébergements mutualisés. Les signalements de
vulnérabilité sont pris au sérieux, et traités **en privé** jusqu'à la
publication d'un correctif.

## Signaler une vulnérabilité

**Ne pas ouvrir de ticket public pour une faille de sécurité.** Un ticket
public décrit un mode d'exploitation à tous ceux qui lisent le dépôt, avant
qu'un correctif existe.

Deux canaux privés, au choix :

1. **Avis privé GitHub** —
   [ouvrir un avis de sécurité](https://github.com/ytassy/tycms/security/advisories/new).
   C'est le canal préféré : la discussion reste liée au dépôt, l'accusé de
   réception est immédiat, et rien n'est visible publiquement. Depuis l'onglet
   *Security*, le même formulaire s'ouvre par **Report a vulnerability** ; cette
   page affiche cette politique, d'où le lien direct ci-dessus.
2. **Page du projet** — <https://www.tycms.fr/security>.

Merci de préciser, autant que possible :

- la version concernée et l'environnement (PHP, type d'hébergement) ;
- les étapes de reproduction, avec la requête ou le scénario minimal ;
- l'impact que vous avez constaté ou supposé ;
- les protections déjà en place que vous avez contournées.

## Versions prises en charge

| Version | Correctifs de sécurité |
| --- | --- |
| 1.1.x | oui |
| toute version antérieure | non |

Seule la dernière version mineure reçoit des correctifs. Une installation doit
être à jour pour être considérée comme prise en charge.

## Ce à quoi vous pouvez vous attendre

- **Accusé de réception** sous quelques jours ouvrés.
- Une première évaluation, y compris lorsque le signalement ne relève pas d'une
  vulnérabilité : vous saurez pourquoi.
- L'annonce du correctif avant toute publication, si vous le souhaitez.
- Aucune rémunération : le projet n'a pas de programme de prime.

Le projet est mené par une seule personne ; ces délais sont indicatifs et non
contractuels.

## Périmètre

Sont concernés : le code livré dans les archives de release, les assets
publics, l'outil de mise à jour, le serveur de distribution et les scripts de
publication.

Ne relèvent pas de cette politique :

- les vulnérabilités d'un hébergement, d'un serveur web ou d'une base de
  données qui ne sont pas dues au code de TyCMS ;
- les extensions tierces, à signaler à leur éditeur ;
- les dépendances tierces : signalez-les à leur mainteneur, et prévenez-nous
  si TyCMS les embarque dans une version livrée ;
- l'absence de fonctionnalité, les demandes produit et les erreurs
  d'utilisation sans conséquence sur la sécurité.

## Pour les contributeurs et les auditeurs

Le déroulé de l'audit interne, les constats et leur suivi sont documentés dans
[`Docs/IA/Security/README.md`](Docs/IA/Security/README.md). La configuration
destinée aux exploitants — domaines autorisés, iframes, CSP et WAF par Space —
est décrite dans [`Docs/PLUGIN_SECURITE.md`](Docs/PLUGIN_SECURITE.md).

Ce document de politique ne remplace ni les guides techniques, ni les contrats
de sécurité décrits ailleurs dans `Docs/`.
