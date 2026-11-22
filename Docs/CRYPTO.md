# Chiffrement applicatif

`Core\Helper\Crypt\CryptHelper` est le service partagé de chiffrement de Core.
Une classe métier qui compose `TraitCoreSharedHelpers` ou `TraitAllHelpers`
utilise `$this->coreCryptHelper`.

```php
$encrypted = $this->coreCryptHelper->encrypt('secret', 'mon-extension:jeton:v1');
$plainText = $this->coreCryptHelper->decrypt($encrypted, 'mon-extension:jeton:v1');

// Clé fournie explicitement pour un besoin indépendant de Configuration.secret.
$encrypted = $this->coreCryptHelper->encrypt('secret', 'mon-extension:jeton:v1', password: $key);
```

Le deuxième argument est un usage stable et distinct pour chaque type de donnée.
Les mêmes usages et la même clé doivent être fournis pour déchiffrer.
Le contexte HKDF est `tycms:crypt:v1:` suivi de l’usage ; ce contexte et le
préfixe de sortie sont stables et participent au contrat de chiffrement.
Si `password` vaut `null`, le helper utilise `Configuration.secret`. Sa valeur
peut être toute chaîne non vide, sans format imposé. Le helper dérive une clé
de 32 octets par HKDF-SHA-256, distincte selon l’usage, puis chiffre avec
AES-256-GCM et un IV aléatoire de 12 octets. Le tag d’authentification fait
16 octets. La sortie `tycmscrypt:v1:` suivie de base64 se stocke dans du texte
ou du JSON. Un mauvais usage, une mauvaise clé ou des données modifiées
provoquent une exception.

La dérivation assure la taille de clé attendue par AES ; elle n’ajoute pas
d’entropie à un secret facile à deviner. Utiliser une valeur longue et aléatoire
pour `Configuration.secret`, et la conserver identique entre déploiements.
Une valeur vide est refusée. Changer la valeur sans rechiffrer les données
empêche leur déchiffrement.

Le format antérieur `SsoCrypto` (libsodium) n’est plus lu. Aucun schéma ou
migration de données n’est ajouté pour ce changement. Les secrets fournisseurs
de développement doivent être réenregistrés ; les liens d’identité sont
réécrits lors d’une nouvelle connexion et les autorisations OAuth doivent
être renouvelées pour fournir des jetons lisibles.
