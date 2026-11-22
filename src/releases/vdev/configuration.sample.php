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
    //     'pdf', 'txt', 'scss', 'csv', 'json',
    //     'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
    //     'mp3', 'wav', 'ogg', 'm4a', 'aac', 'mp4', 'webm',
    //     'zip', 'm3u8', 'ts', 'key', 'keyinfo', 'crypt',
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
