# Notes de la version en préparation

Ce document présente uniquement les changements utiles aux utilisateurs de Tycms.

## Nouveautés

- Serveur MCP : TyCMS expose un point d'entrée `/ai/mcp` (Model Context Protocol) permettant à un agent IA de lire et modifier le site. L'accès s'effectue avec un jeton d'API rattaché à un compte de service ; les actions à effet externe (e-mails, notifications, Google) sont indisponibles par ce canal.
- Comptes de service : nouvel écran réservé au super administrateur pour créer des comptes sans connexion interactive, gérer leurs jetons d'API et les supprimer. Leurs groupes et rôles se gèrent depuis la liste des utilisateurs, qui distingue désormais les types de comptes.
- Automatisation : les workflows GitHub Actions envoient une notification Telegram, Slack ou Discord selon les secrets configurés : échec et réussite sur les branches de production, échec et résultats des traitements de tickets et d'analyse. Un push sur une branche de développement ne notifie pas.

## Changements


## Corrections

- Automatisation : l'action `core::listUsers` accepte à nouveau ses quatre paramètres (`page`, `perPage`, `search`, `type`). Le catalogue du langage utilisait une signature de secours obsolète qui masquait le paramètre `type` ajouté.
