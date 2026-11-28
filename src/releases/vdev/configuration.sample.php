<?php

require_once __DIR__ . '/configuration.base.php';

/**
 * Copier ce fichier vers configuration.php, puis décommenter les réglages du site.
 * Les options commentées restent héritées de ConfigurationBase et de ses évolutions.
 * Ne jamais inscrire de secrets réels dans cet exemple versionné.
 */
class Configuration extends ConfigurationBase
{
    // Conserver la version du contrat utilisé pour rédiger la configuration locale.
    public int $configVersion = 1;

    // Garder cette déclaration active : l'updater modifie ce booléen littéral.
    public $offline = false;

    // --- Réglages importants à définir avant le premier démarrage ---

    // Secret long et aléatoire : le conserver exactement entre les déploiements.
    // public $secret = 'REMPLACER_PAR_UN_SECRET_LONG_ET_ALEATOIRE';

    // Jeton attendu par la crontab sur POST /cron.
    // public $cron_token = 'REMPLACER_PAR_UN_JETON_LONG_ET_ALEATOIRE';

    // Jeton du worker de la file de jobs, appelé par un second CRON.
    // public $job_worker_token = 'REMPLACER_PAR_UN_JETON_LONG_ET_ALEATOIRE';

    // Déclenchement de la file par le trafic du site. Actif par défaut ;
    // false s'en remet au seul CRON de la file.
    // public $job_traffic_trigger = true;

    // Connexion SQL : utiliser un compte dédié à la base de cette installation.
    // public $db_host = '127.0.0.1';
    // public $db_dbname = 'votre_base';
    // public $db_user = 'votre_utilisateur';
    // public $db_password = 'REMPLACER_PAR_LE_MOT_DE_PASSE_SQL';

    // Identité du site et compte administrateur créé lors de l'installation initiale.
    // Remplacer les identifiants d'exemple hérités de la base avant de démarrer.
    // public $site_name = 'Mon site';
    // public $first_admin_email = 'admin@example.com';
    // public $first_admin_password = 'REMPLACER_PAR_UN_MOT_DE_PASSE_LONG_ET_UNIQUE';

    // --- Autres options : ne décommenter que les surcharges nécessaires ---

    // Développement et diagnostic : garder false en production.
    // RESET_DATABASE réinitialise la base à chaque requête : garder false.
    // public $debug = false;
    // public $dev = false;
    // public $RESET_DATABASE = false;

    // Erreurs PHP : display_errors affiche les erreurs dans la réponse HTTP.
    // Le garder à false en production pour ne pas exposer chemins et détails internes.
    // log_errors conserve une trace dans le journal du serveur, même quand l'affichage est coupé.
    // error_reporting_level règle les erreurs journalisées, pas leur affichage.
    // Production recommandée : E_ALL & ~E_DEPRECATED. Développement : E_ALL.
    // public bool $display_errors = false;
    // public bool $log_errors = true;
    // public int $error_reporting_level = E_ALL & ~E_DEPRECATED;

    // Extensions Core facultatives : Tools, Matrix et Automation sont actives
    // par défaut. TyCatalog suit dev si sa clé est omise : actif en DEV, inactif
    // en production.
    // Une valeur explicite true/false prime sur ce défaut ; booléens PHP uniquement.
    // public array $core_extensions_enabled = [
    //     'plg_tools' => true,
    //     'plg_matrix' => true,
    //     'plg_automation' => true, // Passer à false pour la désactiver.
    //     // 'plg_ty_catalog' => true, // Activation explicite, même en production.
    // ];

    // Sessions : 'php' ou 'database'. La base permet de révoquer toutes les sessions
    // d'un compte après une réinitialisation du mot de passe ; PHP ne le permet pas.
    // Durées exprimées en secondes ; cookie_secure doit rester true en production HTTPS.
    // public $session_handler = 'database';
    // public $session_lifetime = 3600;
    // public $session_php_start_options = [
    //     'cookie_httponly' => true,
    //     'cookie_secure' => true,
    //     'use_strict_mode' => true,
    // ];

    // Récupération du compte : délai entre deux demandes et durée du lien, en secondes.
    // public int $password_reset_request_delay = 60;
    // public int $password_reset_token_lifetime = 1800;

    // Contenu HTML affiché pendant la maintenance.
    // public $offline_message = '<p>Ce site est en maintenance. Merci de revenir ultérieurement.</p>';

    // Pilote SQL et paramètres TLS à adapter à l'hébergement.
    // public $db_type = 'mysqli';
    // public $db_encryption = 0;
    // public $db_ssl_verifyservercert = false;
    // public $db_ssl_key = '';
    // public $db_ssl_cert = '';
    // public $db_ssl_ca = '';
    // public $db_ssl_cipher = '';

    // Autoriser l'envoi des e-mails ; la configuration SMTP se règle dans l'administration.
    // public $mailonline = false;

    // Journal des e-mails sortants dans la table mail_logs.
    // Le désactiver pour ne rien stocker ; la purge manuelle reste disponible.
    // public bool $mail_log_enabled = true;
    // Rétention appliquée par la purge horaire du CRON, en jours (1 à 3650).
    // public int $mail_log_retention_days = 90;

    // Plafond global de destinataires d'un événement notifiable (2000 par défaut).
    // Une audience qui le dépasse n'est jamais tronquée : elle est journalisée et
    // aucune notification n'est créée. Toute valeur inférieure à 1 vaut 1.
    // public int $notification_max_recipients = 2000;

    // Mises à jour : serveurs HTTPS ordonnés et clés publiques Ed25519 reconnues.
    // Conserver les valeurs héritées sauf besoin explicite de changer de distribution.
    // Une clé publique n'est pas un secret ; ne jamais placer ici de clé privée.
    // public array $update_servers = [
    //     'https://update.tycms.yanntassy.fr/index.php',
    //     'https://update.tycms.fr/index.php',
    // ];
    // public array $update_trusted_public_keys = [
    //     'release-1' => 'eoUy4eKEJ0H2I5t1XRMCnBYYDnH/m5pbqFievQZyxnk=',
    // ];
    // Canal accepté : 'prod' (défaut) ou 'beta'. Réserver 'beta' aux installations d'essai :
    // les versions de bêta y sont proposées avant leur publication en prod.
    // public string $update_channel = 'prod';

    // Chemins disque : les médias et polices doivent rester accessibles aux URL publiques.
    // Les logs et fichiers temporaires restent hors de la racine HTTP public/.
    // public string $medias_root = APP_ROOT . '/public/medias';
    // public string $fonts_root = APP_ROOT . '/public/assets/fonts';
    // public string $ty_catalog_storage_root = APP_ROOT . '/var/ty_catalog';
    // public string $log_path = APP_ROOT . '/logs';
    // Journaux privés : durée et volume maximum indépendants, actif et archives inclus.
    // Une surcharge de tableau doit conserver les trois niveaux.
    // public array $log_levels = [
    //     'INFO' => ['retention_days' => 90, 'max_bytes' => 50_000_000],
    //     'WARNING' => ['retention_days' => 90, 'max_bytes' => 50_000_000],
    //     'ERROR' => ['retention_days' => 90, 'max_bytes' => 50_000_000],
    // ];
    // public int $log_max_file_bytes = 10_000_000;
    // public string $tmp_path = APP_ROOT . '/tmp';

    // Limites de taille en octets : plafond absolu de 5 000 000 pour le JSON d'une page.
    // La limite d'un diagramme ne peut pas dépasser celle de la page.
    // public int $page_max_size = 1_000_000;
    // public int $page_diagram_max_size = 250_000;
    // public int $media_max_upload_size = 25 * 1024 * 1024;

    // Extensions de médias acceptées, sans le point initial.
    // Attention : toute surcharge de tableau remplace la liste héritée entière.
    // public array $media_allowed_extensions = [
    //     'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg',
    //     'pdf', 'txt', 'scss', 'js', 'csv', 'json',
    //     'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    //     'mp3', 'wav', 'ogg', 'm4a', 'aac', 'mp4', 'webm',
    //     'zip', 'm3u8', 'ts', 'key', 'keyinfo', 'crypt',
    // ];

    // Extensions réservées aux comptes ROOT pour le téléversement, le
    // renommage et l'édition. Elles doivent aussi figurer dans la liste
    // ci-dessus. Un SCSS compilé plus tard par une template ou un JS chargé
    // par un outil changeraient le comportement du site : leur maîtrise reste
    // donc au super administrateur.
    // public array $media_root_only_extensions = [
    //     'scss', 'js',
    // ];

    // Sans constructeur local, PHP appelle automatiquement celui de la base.
    // Si vous en ajoutez un, appeler le parent après vos propres affectations.
    // public function __construct()
    // {
    //     // Charger ici, par exemple, les paramètres de l'environnement.
    //
    //     parent::__construct();
    // }
}
