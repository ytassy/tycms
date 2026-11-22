<?php

namespace YTassy\Tycms\DB\Migrations;

use YTassy\Tycms\Application;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\DB\Migrations\Core\Migrations;
use YTassy\Tycms\Tools\CriticalSection;

class Migration
{
    private Application $app;
    private Database $db;
    private array $runnedMigrationByExtension = [];

    /** Vérifie l'installation initiale sans créer la table ni lancer de migration. */
    public static function tableExists(Database $db): bool
    {
        return $db->selectOneOrNull(<<<SQL
            SELECT 1 AS present
            FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name = 'migrations'
              AND table_type = 'BASE TABLE'
            LIMIT 1
            SQL) !== null;
    }

    /**
     * Liste les versions encore absentes de la table `migrations`, dans l'ordre
     * déclaré par l'extension. Ne crée rien et n'exécute aucune migration.
     *
     * @return list<string>
     */
    public static function getPendingVersions(Database $db, string $extension, IMigration $migrationObj): array
    {
        // Sans table, aucune version n'est enregistrée : tout est en attente.
        if (!self::tableExists($db)) {
            return $migrationObj->getMigrations();
        }

        $rows = $db->select(
            'SELECT version FROM `migrations` WHERE extension = %',
            [strtolower($extension)],
        );

        $applied = array_map(
            static fn(object $row): string => strtolower((string) $row->version),
            $rows,
        );

        return array_values(array_filter(
            $migrationObj->getMigrations(),
            static fn(string $version): bool => !in_array(strtolower($version), $applied, true),
        ));
    }

	public function __construct(Application $app)
	{
        $this->app = $app;
        $this->db = $app->getDatabase();

        $migrations = $this->db->executeMultiple('CREATE TABLE IF NOT EXISTS `migrations` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `extension` varchar(50) NOT NULL,
  `version` varchar(50) NOT NULL,
  `date` datetime NOT NULL DEFAULT current_timestamp(),
  `result` longtext NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_migrations_ev` (`extension`, `version`),
    KEY `idx_migrations_ed` (`extension`, `date`),
    KEY `idx_migrations_date` (`date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT id, extension, version, date FROM `migrations` ORDER BY extension, date;
');

        $this->runnedMigrationByExtension = [];
        foreach($migrations as $mig) $this->runnedMigrationByExtension[strtolower($mig->extension)][strtolower($mig->version)] = $mig->date;

        // Load Migration
    }

    public function runCoreMigrations(){
        $this->runMigrations('core', new Migrations());
    }
    
    public function runMigrations(string $extension, IMigration $migrationObj){
        // LOWER
        $extension = strtolower($extension);

        $versions = $migrationObj->getMigrations();
        //natsort($versions); // Useless

        // Search Versions to execute
        $migrationToRuns = [];
        foreach($versions as $version)
        {
            if (isset($this->runnedMigrationByExtension[$extension][$version]) == false) {
                $migrationToRuns[] = $version;
            }
        }

        if (count($migrationToRuns) > 0)
        {
            // Keep the historical location for existing local Configuration classes.
            $s = new CriticalSection('migration_running');

            $s->execute(function() use($extension, $migrationObj, $migrationToRuns) {
                $this->_runMigrations($extension, $migrationObj, $migrationToRuns);
            });
        }
    }

    private function _runMigrations(string $extension, IMigration $migrationObj,
        array $migrationToRuns){

        foreach($migrationToRuns as $version){
            
            // CHECK AGAIN
            $existingCheck = $this->db->select('SELECT id, date FROM `migrations` WHERE extension = % AND version = %;', [
                $extension, $version
            ]);

            if (count($existingCheck) > 0) continue; // Already Executed

            // Run Migration
            $results = $migrationObj->runMigration($version);

            $this->db->execute('INSERT into migrations (extension, version, date, result)
                VALUES (%, %, current_timestamp(), %)', [
                    $extension, $version, json_encode($results, JSON_PRETTY_PRINT)
            ]);

        }
    }
}
