<?php

namespace YTassy\Tycms\DB;

use YTassy\Tycms\Application;
use YTassy\Tycms\DB\ORM\BaseOrmObject;
use YTassy\Tycms\DB\ORM\DatabaseOrm;
use YTassy\Tycms\Exceptions\ApplicationException;
use YTassy\Tycms\Exceptions\TechException;
use YTassy\Tycms\Tools\DateTools;

/**
 * Gère la connexion MySQL et l'exécution des requêtes SQL.
 */
class Database
{
	private Application $app;
    private string $db_type = '';
	private string $db_host = '';
	private string $db_user = '';
	private string $db_password = '';
	private string $db_dbname = '';
	//private string $db_prefix = 'app_';
	private int $db_encryption = 0; // 0=Default  / 1=Authentification à sens unique / 2=Double Authentification
	private bool $db_ssl_verifyservercert = false;
	private string $db_ssl_key = '';
	private string $db_ssl_cert = '';
	private string $db_ssl_ca = '';
	private string $db_ssl_cipher = '';

	private DatabaseOrm $db_orm;

    /**
     * Connexion MySQL active.
     *
     * @var \mysqli|null
     */
    private $connection = null;

	/**
	 * Initialise une connexion à la base de données depuis la configuration de l'application.
	 *
	 * @param Application $app Application fournissant la configuration de base de données.
	 *
	 * @throws ApplicationException Si la connexion ou la configuration SSL échoue.
	 * @throws TechException Si le pilote configuré ou l'extension mysqli est indisponible.
	 */
	public function __construct(Application $app)
	{
		$conf = $app->getConf();

		$this->app = $app;
        $this->db_type = $conf->db_type; // mysqli / mysql
        $this->db_host = $conf->db_host;
        $this->db_user = $conf->db_user;
        $this->db_password = $conf->db_password;
        $this->db_dbname = $conf->db_dbname;
        //$this->db_prefix = $conf->db_prefix;
        $this->db_encryption = $conf->db_encryption;
        $this->db_ssl_verifyservercert = $conf->db_ssl_verifyservercert;
        $this->db_ssl_key = $conf->db_ssl_key;
        $this->db_ssl_cert = $conf->db_ssl_cert;
        $this->db_ssl_ca = $conf->db_ssl_ca;
        $this->db_ssl_cipher = $conf->db_ssl_cipher;


		$type = strtolower($this->db_type);

		if (!in_array($type, ['mysqli', 'mysql'], true)) {
			throw new TechException(sprintf('Unsupported database driver: %s.', $this->db_type));
		}

		if (!extension_loaded('mysqli')) {
			throw new TechException('The mysqli PHP extension is required to connect to the database.');
		}

		$connection = mysqli_init();

		if ($connection === false) {
			throw new TechException('Unable to initialize the database connection.');
		}

		$flags = 0;
		if ($this->db_encryption > 0) {
			if (!mysqli_ssl_set(
				$connection,
				$this->db_ssl_key ?: null,
				$this->db_ssl_cert ?: null,
				$this->db_ssl_ca ?: null,
				null,
				$this->db_ssl_cipher ?: null
			)) {
				throw new TechException('Unable to configure SSL for the database connection.');
			}

			if (defined('MYSQLI_OPT_SSL_VERIFY_SERVER_CERT')) {
				mysqli_options($connection, MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, $this->db_ssl_verifyservercert);
			}

			$flags |= MYSQLI_CLIENT_SSL;
		}

		if (!mysqli_real_connect(
			$connection,
			$this->db_host,
			$this->db_user,
			$this->db_password,
			$this->db_dbname,
			null,
			null,
			$flags
		)) {
			throw new TechException(sprintf('Unable to connect to the database: %s.', mysqli_connect_error()));
		}

		// Define connection
		$this->connection = $connection;

		// mysqli_real_escape_string() must use the actual connection charset.
		if (!mysqli_set_charset($this->connection, 'utf8mb4')) {
			throw new TechException(sprintf('Unable to set the database charset: %s.', mysqli_error($this->connection)));
		}

		// Set Timezone at UTC+0
		// comma ça now() = current_timestamp() = utc_timestamp()
		$this->execute("SET time_zone = '+00:00'");
		
		// Load ORM
		$this->db_orm = new DatabaseOrm($this->app, $this);

		// Reset Data BASE ?

		if ($conf->RESET_DATABASE) {
			// DROP ALL TABLES
			$this->executeMultiple("SET FOREIGN_KEY_CHECKS = 0;

SET @stmt = NULL;

SELECT CONCAT('DROP TABLE IF EXISTS ', GROUP_CONCAT(CONCAT('`', table_name, '`')), ';')
INTO @stmt
FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_type = 'BASE TABLE';

IF @stmt IS NOT NULL THEN
    PREPARE stmt FROM @stmt;
    EXECUTE stmt;
    DEALLOCATE PREPARE stmt;
END IF;

SET FOREIGN_KEY_CHECKS = 1;");
		}
	}

	/**
	 * Remplace les marqueurs {@code %} ou {@code %%} d'une requête par des valeurs SQL échappées.
	 *
	 * Chaque marqueur doit correspondre à une valeur, dans le même ordre que le tableau
	 * {@see $valuesToQuote}. Les valeurs {@code null}, entières, booléennes, chaînes et
	 * tableaux sont prises en charge.
	 *
	 * @param string $sql Requête SQL contenant un marqueur {@code %} ou {@code %%} par valeur à insérer.
	 * @param array<int, mixed> $valuesToQuote Valeurs à insérer dans les marqueurs.
	 *
	 * @return string Requête SQL dont les marqueurs ont été remplacés.
	 *
	 * @throws \Exception Si le nombre de marqueurs est incorrect ou si une valeur n'est pas prise en charge.
	 */
	public function formatSql(string $sql, array $valuesToQuote = [])
	{
		$parts = explode(str_contains($sql, "%%") ? '%%':'%', $sql);

		if (count($parts) != count($valuesToQuote) + 1) throw new TechException('invalid SQL code reguarding number of parameters given');
		
		// Reset $sql
		$sql = '';

		foreach ($parts as $i => $part)
		{
			$sql .= $part;

			if ($i > count($valuesToQuote) - 1) continue; // Ignore last

			$value = $valuesToQuote[$i];

			if ($value === null) $sql .= 'NULL';
			else if (is_int($value)) $sql .= (string) $value;
			else if (is_float($value)) {
				if (!is_finite($value)) throw new TechException('invalid float value given at setQuery');
				$sql .= sprintf('%.17g', $value);
			}
			else if (is_bool($value)) $sql .= ($value ? 'true' : 'false');
			else if (is_string($value)) $sql .= $this->quote($value);
			else if (is_array($value)) $sql .= $this->quote(json_encode($value, JSON_THROW_ON_ERROR));
			else if ($value instanceof \DateTimeInterface) $sql .= $this->quote(DateTools::toSql($value));
			else if ($value instanceof FormatIn) $sql .= $value->toSql($this);
			else if (is_object($value)) $sql .= $this->quote(json_encode($value, JSON_THROW_ON_ERROR));
			else throw new TechException('invalid value given at setQuery');
		}

		return $sql;
	}
	
	public function selectOneOrNull(string $query, ?array $valuesToQuote = null): ?object
	{
		$res = $this->select($query, $valuesToQuote);
		if (count($res) != 1) return null;
		return $res[0];
	}

	/**
	 * @template T of object
	 * @param class-string<T> $className
	 * @param string $query
	 * @param array $valuesToQuote
	 * @return array<T>
	 */
	public function selectOrm(string $className, string $query, ?array $valuesToQuote = null): array{
		return $this->db_orm->select($className, $query, $valuesToQuote);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $className
	 * @param string $query
	 * @param array $valuesToQuote
	 * @return T|null
	 */
	public function selectOrmOneOrNull(string $className, string $query, ?array $valuesToQuote = null): ?BaseOrmObject{
		return $this->db_orm->selectOneOrNull($className, $query, $valuesToQuote);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $className
	 * @param string $query
	 * @param array $valuesToQuote
	 * @return T
	 */
	public function selectOrmOneOrFail(string $className, string $query, ?array $valuesToQuote = null): BaseOrmObject{
		return $this->db_orm->selectOneOrFail($className, $query, $valuesToQuote);
	}

	

	/**
	 * Exécute une requête SELECT et retourne les lignes obtenues.
	 *
	 * @param string $query Requête SQL SELECT à exécuter.
	 * @param array<int, mixed>|null $valuesToQuote Valeurs remplaçant les marqueurs {@code %} de la requête.
	 *
	 * @return array<int, object> Lignes retournées, indexées numériquement.
	 *
	 * @throws \Exception Si les valeurs fournies ne correspondent pas aux marqueurs de la requête.
	 * @throws TechException Si la requête échoue ou ne retourne pas de jeu de résultats.
	 */
	public function select(string $query, ?array $valuesToQuote = null): array
	{
		if ($valuesToQuote !== null)
		{
			$query = $this->formatSql($query, $valuesToQuote);
		}

		$result = mysqli_query($this->connection, $query);

		if ($result === false) {
			throw new TechException(sprintf('Unable to execute the SELECT query: %s.', mysqli_error($this->connection)));
		}

		if (!$result instanceof \mysqli_result) {
			throw new TechException('The query did not return a result set.');
		}

		$currentResult = [];
		while ($row = mysqli_fetch_object($result)) {
			$currentResult[] = $row;
		}

		return $currentResult;
	}

	/**
	 * Échappe une valeur et l'entoure d'apostrophes pour l'utiliser comme littéral SQL.
	 *
	 * Cette méthode est réservée aux valeurs SQL ; elle ne doit pas être utilisée pour
	 * échapper un nom de table ou de colonne.
	 *
	 * @param string $value Valeur à échapper.
	 *
	 * @return string Valeur SQL échappée et entourée d'apostrophes.
	 */
	public function quote(string|int|float|bool $value): string
	{
		if (is_string($value)) return "'" . mysqli_real_escape_string($this->connection, $value) . "'";
		if (is_bool($value)) return $value ? 'TRUE': 'FALSE';
		if (is_int($value)) return (string) $value;
		if (is_float($value)){
			if (!is_finite($value)) throw new TechException('invalid float value given at quote');
			return (string) $value;
		} 
		return 'NULL';
	}

	/**
	 * Exécute une requête SQL ne nécessitant pas de récupérer de résultat.
	 *
	 * Convient notamment aux requêtes INSERT, UPDATE, DELETE et DDL.
	 *
	 * @param string $query Requête SQL à exécuter.
	 * @param array<int, mixed>|null $valuesToQuote Valeurs remplaçant les marqueurs {@code %} de la requête.
	 *
	 * @return int Nombre de lignes affectées par la requête.
	 *
	 * @throws \Exception Si les valeurs fournies ne correspondent pas aux marqueurs de la requête.
	 * @throws TechException Si la requête échoue.
	 */
	public function execute(string $query, ?array $valuesToQuote = null): int
	{
		if ($valuesToQuote !== null)
		{
			$query = $this->formatSql($query, $valuesToQuote);
		}

		$result = mysqli_query($this->connection, $query);

		if ($result === false) {
			throw new TechException(sprintf('Unable to execute the query: %s.', mysqli_error($this->connection)));
		}

		if ($result instanceof \mysqli_result) {
			mysqli_free_result($result);
		}

		return mysqli_affected_rows($this->connection);
	}

	/**
	 * Exécute plusieurs instructions SQL séparées par un point-virgule.
	 *
	 * Tous les résultats intermédiaires sont consommés. Par défaut, seul le résultat de
	 * la dernière instruction est retourné. Lorsque {@see $returnAllResults} est activé,
	 * les résultats de toutes les instructions sont retournés dans leur ordre d'exécution.
	 *
	 * @param string $queries Instructions SQL à exécuter.
	 * @param array<int, mixed>|null $valuesToQuote Valeurs remplaçant les marqueurs {@code %} des instructions.
	 * @param bool $returnAllResults Indique si tous les résultats doivent être retournés.
	 *
	 * @return array<int, mixed>|int Résultat de la dernière instruction, ou la liste de tous les résultats.
	 *
	 * @throws TechException Si une des instructions échoue.
	 */
	public function executeMultiple(string $queries, ?array $valuesToQuote = null, bool $returnAllResults = false): array|int
	{
		if ($valuesToQuote !== null) {
			$queries = $this->formatSql($queries, $valuesToQuote);
		}

		if (!mysqli_multi_query($this->connection, $queries)) {
			throw new TechException(sprintf('Unable to execute the SQL instructions: %s.', mysqli_error($this->connection)));
		}

		$lastResult = 0;
		$allResults = [];

		do {
			$result = mysqli_store_result($this->connection);

			if ($result instanceof \mysqli_result) {
				$currentResult = [];
				while ($row = mysqli_fetch_object($result)) {
					$currentResult[] = $row;
				}

				mysqli_free_result($result);
			} else {
				$currentResult = mysqli_affected_rows($this->connection);
			}

			$lastResult = $currentResult;
			if ($returnAllResults) {
				$allResults[] = $currentResult;
			}

			if (!mysqli_more_results($this->connection)) {
				break;
			}

			if (!mysqli_next_result($this->connection)) {
				throw new TechException(sprintf('Unable to execute the SQL instructions: %s.', mysqli_error($this->connection)));
			}
		} while (true);

		return $returnAllResults ? $allResults : $lastResult;
	}

	/**
	 * Retourne l'identifiant auto-incrémenté généré par la dernière insertion.
	 *
	 * @return int Identifiant généré, ou 0 si la dernière requête n'a pas généré d'identifiant.
	 */
	public function getLastInsertId(): int
	{
		return mysqli_insert_id($this->connection);
	}



    
}
