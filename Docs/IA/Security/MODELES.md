# Réglages proposés pour l'audit

Ces réglages sont des recommandations de travail, pas des performances mesurées
sur ce projet. Choisir uniquement les modèles et efforts proposés dans le
sélecteur de Yann ; leur disponibilité dépend du compte et du client.

La [documentation officielle OpenAI](https://learn.chatgpt.com/docs/models)
présente Astra pour les tâches exigeantes, Sol pour le travail courant et
Luna pour les tâches ciblées répétitives. Elle précise qu'augmenter l'effort
prend davantage de temps et de tokens. Le choix ci-dessous applique ces
principes à cet audit ; il n'est pas une prescription officielle de sécurité.

## Profils de travail

| Profil | Modèle proposé | Effort de départ | Utilisation |
| --- | --- | --- | --- |
| S | GPT-6.2 Sol ; repli GPT-6.1 Sol | Moyen (`medium`), élevé (`high`) pour une analyse de code | Inventaires bornés, dépendances, configurations, tenue du suivi, correctif simple déjà décidé. |
| A | GPT-6.2 Astra ; repli GPT-6 Astra | Élevé (`high`) | Analyse de sécurité d'un domaine, suivi des contrôles entre couches et conception du correctif. |
| A+ | Même modèle Astra | Élevé (`high`), puis niveau supérieur disponible si difficulté concrète | ACL/délégation, identités fédérées, automatisation, signatures, updater et seconde lecture des risques graves. |

Si les modèles 6.2 ne sont pas proposés, utiliser les replis sans interrompre
le chantier. Une table de correspondance propre à chaque lot figure dans
[le plan](PLAN.md). Ne pas confondre nom de modèle, effort et mode de vitesse.

## Économiser le quota sans affaiblir l'analyse

- Commencer par un lot délimité et une révision connue ; transmettre le suivi
  dans une nouvelle conversation plutôt que recopier l'ensemble du dépôt.
- Utiliser Sol pour les extractions et la documentation. Réserver Astra aux
  raisonnements de sécurité et à la vérification des conclusions importantes.
- Garder l'effort élevé sur les frontières de privilèges. Monter davantage
  seulement pour une chaîne de contrôles difficile ou une hypothèse non résolue.
- Demander une seconde lecture ciblée pour un constat critique/élevé et son
  correctif, en recherchant aussi les raisons qui invalideraient le constat.
  Elle peut se faire dans une session séparée, sans agents parallèles.
- Éviter le mode Ultra pour cette démarche séquentielle : la documentation
  le décrit comme utilisant des sous-agents. Aucun parallélisme d'agents n'est
  nécessaire au périmètre actuel et il n'a pas été demandé.
- N'utiliser un modèle léger comme Luna que pour une extraction mécanique
  relue ensuite ; ne pas lui confier seul la conclusion sur une autorisation,
  une signature ou un risque de prise de compte.

La preuve dans le code et le test du refus priment sur le niveau d'effort.
Un modèle plus coûteux ne compense ni une route oubliée, ni une hypothèse
d'hébergement inconnue, ni un test exécuté sur le mauvais environnement.

## Choix simple conseillé

**Astra + effort élevé** pour lancer un lot de sécurité ; **Sol + effort moyen**
pour mettre en forme le suivi, ou **élevé** pour un correctif limité autorisé.
Le niveau supérieur reste réservé aux quelques sujets qui le justifient.
La recommandation n'applique aucun changement aux réglages du client.
