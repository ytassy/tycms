<?php

class ConfigurationBase
{
    /** Version du contrat attendu ; indépendante de la version du logiciel. */
    public const CURRENT_CONFIG_VERSION = 1;

    /** Version de la configuration locale ; garder 1 comme défaut pour les anciens fichiers. */
    public int $configVersion = 1;

    private const OPTIONAL_CORE_EXTENSIONS = [
        'plg_tools' => true,
        'plg_matrix' => true,
        'plg_automation' => true,
    ];

    /** @var array<string, bool> Tools, Matrix et Automation sont actifs par défaut ; TyCatalog omis suit le mode DEV. */
    public array $core_extensions_enabled = self::OPTIONAL_CORE_EXTENSIONS;

	public $debug = false;
	public $dev = false;
	public $RESET_DATABASE = false; // RESET DATA BASE AT EACH REQUEST


	// php | database : seule la base permet de révoquer toutes les sessions d'un compte
	// après une réinitialisation de mot de passe ; avec PHP, les autres sessions restent actives.
	public $session_handler = 'database';
	public $session_lifetime = 3600; // secondes

	/** Délai minimal entre deux demandes de récupération du même compte, en secondes. */
	public int $password_reset_request_delay = 60;
	/** Durée de validité d'un lien de récupération, en secondes (30 minutes). */
	public int $password_reset_token_lifetime = 1800;
	public $session_php_start_options = [
		'cookie_httponly' => true,
		'cookie_secure'   => true,
		'use_strict_mode' => true,
	];

    public $offline = false;
	public $offline_message = '
		<div style="font-size: 300%; margin-bottom:30px"><i class="fa-solid fa-screwdriver-wrench"></i></div>
		<div style="font-size: 130%;">Ce site est en maintenance. <br />Merci de revenir ultérieurement.</div>
	';
    // Clé de chiffrement des secrets : format libre, valeur longue et aléatoire recommandée.
    // Conserver exactement la même valeur entre les déploiements.
    public $secret = 'xxxxxxxxxxxx';

    // Jeton de la route CRON globale, appelée chaque minute par la crontab.
    // Laisser vide pour désactiver la route /cron.
    public string $cron_token = '';

    // Serveurs de distribution HTTPS, consultés dans cet ordre en cas de panne.
    // Le client arrête la vérification sur signature invalide ou paquet altéré.
    public array $update_servers = [
        'https://update.tycms.yanntassy.fr/index.php',
        'https://update.tycms.fr/index.php',
    ];

    /** @var array<string, string> Clés publiques Ed25519 reconnues, en base64. */
    public array $update_trusted_public_keys = [
        'release-1' => 'eoUy4eKEJ0H2I5t1XRMCnBYYDnH/m5pbqFievQZyxnk=',
    ];

    // Canal de diffusion accepté : « prod » (défaut) ou « beta ».
    // « beta » fait aussi proposer les versions de bêta, à réserver aux installations d'essai.
    public string $update_channel = 'prod';

	public $site_name = 'Yann Tassy';

	public $first_admin_email = 'admin@admin15531.org';
	public $first_admin_password = 'admin';
	
	public $db_type = 'mysqli';
	public $db_host = '127.0.0.1';
	public $db_user = 'xxxxxxxxxx';
	public $db_password = 'xxxxxxxxxxx';
	public $db_dbname = 'xxxxxxxxxxxx';
	//public $db_prefix = 'app_';
	public $db_encryption = 0;
	public $db_ssl_verifyservercert = false;
	public $db_ssl_key = '';
	public $db_ssl_cert = '';
	public $db_ssl_ca = '';
	public $db_ssl_cipher = '';

	public $mailonline = false;

	/** Chemin disque des médias ; garder le défaut public pour servir les URL /medias/. */
	public string $medias_root = APP_ROOT . '/public/medias';

	/** Bibliothèque partagée ; à exposer sous /assets/fonts/ si déplacée. */
	public string $fonts_root = APP_ROOT . '/public/assets/fonts';

	/** Racine privée des paquets TyCatalog ; hors de la racine HTTP publique. */
	public string $ty_catalog_storage_root = APP_ROOT . '/var/ty_catalog';

	/** Poids maximal du JSON d'une page, en octets ; plafond absolu de 5 000 000. */
	public int $page_max_size = 1_000_000;

	/** Poids maximal d'un SVG de page, données de réédition comprises, en octets. */
	public int $page_diagram_max_size = 250_000;

	/** Taille maximale d'un média téléversé, en octets. */
	public int $media_max_upload_size = 25 * 1024 * 1024;

	/** @var list<string> Extensions de médias acceptées, sans le point initial. */
	public array $media_allowed_extensions = [
		'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg',
		'pdf', 'txt', 'scss', 'csv', 'json',
		'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx',
		'mp3', 'wav', 'ogg', 'm4a', 'aac', 'mp4', 'webm',
		'zip', 'm3u8', 'ts', 'key', 'keyinfo', 'crypt'
	];
	
	public string $log_path = APP_ROOT . '/logs';

    /** Quotas indépendants : fichiers actifs et archives compris, en octets. */
    public array $log_levels = [
        'INFO' => ['retention_days' => 90, 'max_bytes' => 50_000_000],
        'WARNING' => ['retention_days' => 90, 'max_bytes' => 50_000_000],
        'ERROR' => ['retention_days' => 90, 'max_bytes' => 50_000_000],
    ];
    public int $log_max_file_bytes = 10_000_000;
	public string $tmp_path = APP_ROOT . '/tmp';

    public function __construct()
    {
        // Les constructeurs locaux appellent le parent après leurs propres affectations.
        $this->normalize();
    }

    /** Adapter le contrat en mémoire, sans réécrire la configuration ni ses secrets. */
    public function normalize(): void
    {
        // Refuser un contrat inconnu plutôt que d'interpréter des options incompatibles.
        if ($this->configVersion < 1 || $this->configVersion > self::CURRENT_CONFIG_VERSION) {
            throw new UnexpectedValueException('Version de configuration non prise en charge.');
        }

        // Valider les seuls interrupteurs autorisés sans assouplir les extensions indispensables.
        foreach ($this->core_extensions_enabled as $identifier => $enabled) {
            if ((!array_key_exists($identifier, self::OPTIONAL_CORE_EXTENSIONS)
                    && !in_array($identifier, ['plg_ty_catalog', 'plg_automation'], true))
                || !is_bool($enabled)) {
                throw new UnexpectedValueException(
                    'core_extensions_enabled accepte seulement plg_tools, plg_matrix, plg_ty_catalog et plg_automation avec des booléens.'
                );
            }
        }

        // Tools, Matrix et Automation restent actives par défaut ; TyCatalog omis suit dev.
        $this->core_extensions_enabled += self::OPTIONAL_CORE_EXTENSIONS;

        // Ajouter ici les conversions successives lors d'une évolution du contrat.
        // Les nouvelles options simples sont déjà fournies par les propriétés héritées.
        $this->configVersion = self::CURRENT_CONFIG_VERSION;
    }
}
