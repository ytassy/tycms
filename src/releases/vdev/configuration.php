<?php

require_once __DIR__ . '/configuration.base.php';

class Configuration extends ConfigurationBase
{
    public int $configVersion = 1;

    // Réglages propres au site de développement.
    public $debug = true;
    public $RESET_DATABASE = 0; // RESET DATA BASE AT EACH REQUEST
    public $site_name = 'Mon site';
    public $mailonline = true;

    // Afficher toutes les erreurs PHP en développement ; la production garde display_errors à false.
    public bool $display_errors = true;
    public int $error_reporting_level = E_ALL;

    // Désactiver uniquement ces deux extensions facultatives pour ce site.
    public array $core_extensions_enabled = [
        'plg_tools' => true,
        'plg_matrix' => true,
        'plg_automation' => true
    ];


    // Le développement utilise aussi HTTP ; la base conserve cookie_secure pour la production.
    public $session_php_start_options = [
        'cookie_httponly' => true,
        'cookie_secure' => false,
        'use_strict_mode' => true,
    ];

    // Déclaration locale requise par la bascule de maintenance de l’updater.
    public $offline = false;

    // Jeton de la route CRON globale, appelée chaque minute par la crontab.
    // Laisser vide pour désactiver la route /cron.
    public string $cron_token = 'dev';

    public string $job_worker_token = "dev";

    // Limites spécifiques à cet environnement ; les autres options sont héritées.
    public int $page_max_size = 2_000_000;
    public int $page_diagram_max_size = 500_000;

    // Adapter les paramètres fournis par Docker.
    public function __construct()
    {
        // Le mode dev utilise le serveur Vite démarré par start.sh sur :3000.
        $this->dev = filter_var(getenv('APP_DEV') ?: 'true', FILTER_VALIDATE_BOOLEAN);
        $this->db_host = getenv('DB_HOST') ?: 'mariadb';
        $this->db_dbname = getenv('DB_NAME');
        $this->db_user = getenv('DB_USER');
        $this->db_password = getenv('DB_PASSWORD');
        $this->secret = getenv('APP_SECRET');

        // Normaliser après les valeurs propres à cet environnement.
        parent::__construct();
    }
}
