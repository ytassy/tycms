<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Framework;

use Configuration;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use YTassy\Tycms\Application;
use YTassy\Tycms\ApplicationRouteRunner;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\DB\Database;
use YTassy\Tycms\Logging\LogEventException;
use YTassy\Tycms\Logging\Logger;
use YTassy\Tycms\Logging\LogRecord;
use YTassy\Tycms\Response\ApiResponse;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Request\Request;
use YTassy\Tycms\Routing\Controller\BaseController;
use YTassy\Tycms\Routing\Route;

final class LoggingTest extends TestCase
{
    private string $root;
    private Configuration $configuration;
    private int $now;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tycms-logging-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        $this->configuration = new Configuration();
        $this->configuration->log_path = $this->root . '/logs';
        $this->configuration->log_max_file_bytes = 16000;
        $this->configuration->log_levels = array_fill_keys(LogRecord::LEVELS, ['retention_days' => 90, 'max_bytes' => 32000]);
        $this->now = strtotime('2026-11-15 12:00:00 UTC');
    }

    protected function tearDown(): void
    {
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) rmdir($entry->getPathname());
            else unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    private function logger(): Logger
    {
        return new Logger($this->configuration, APP_ROOT, fn (): int => $this->now);
    }

    private function write(Logger $logger, string $level = 'INFO', string $code = 'TEST_EVENT'): void
    {
        $logger->log($level, 'application', $code, str_repeat('Message de test. ', 100), ['space_id' => 2, 'email' => 'private@example.test', 'token' => 'secret-token']);
    }

    public function testDefaultsAreIndependentNinetyDaysAndFiftyMegabytes(): void
    {
        $defaults = new Configuration();
        foreach (LogRecord::LEVELS as $level) self::assertSame(['retention_days' => 90, 'max_bytes' => 50_000_000], $defaults->log_levels[$level]);
        self::assertSame(10_000_000, $defaults->log_max_file_bytes);
    }

    public function testRotationRespectsSeparateQuotasIncludingActiveFiles(): void
    {
        $logger = $this->logger();
        $this->write($logger, 'ERROR', 'PRESERVED_ERROR');
        for ($index = 0; $index < 70; $index++) $this->write($logger);
        $status = $logger->status();
        self::assertTrue($status['available']);
        self::assertGreaterThan(1, $status['levels']['INFO']['files']);
        self::assertLessThanOrEqual(32000, $status['levels']['INFO']['bytes']);
        self::assertSame(1, $status['levels']['ERROR']['files']);
        self::assertSame(0, $status['levels']['WARNING']['files']);
        foreach (glob($this->root . '/logs/tycms/info/*') as $file) self::assertLessThanOrEqual(16000, filesize($file));
        $result = $logger->reader()->page(['levels' => ['ERROR']]);
        self::assertSame(['PRESERVED_ERROR'], array_column($result['entries'], 'code'));
        self::assertSame(['space_id' => 2], $result['entries'][0]['context']);
    }

    public function testRetentionCanBeChangedForErrorWithoutChangingOtherLevels(): void
    {
        $this->configuration->log_levels['ERROR']['retention_days'] = 180;
        $logger = $this->logger();
        foreach (LogRecord::LEVELS as $level) $this->write($logger, $level);
        $this->now += 91 * 86400;
        $status = $logger->status();
        self::assertSame(0, $status['levels']['INFO']['files']);
        self::assertSame(0, $status['levels']['WARNING']['files']);
        self::assertSame(1, $status['levels']['ERROR']['files']);
    }

    public function testBackwardClockStillRespectsSegmentSize(): void
    {
        $this->configuration->log_levels['INFO']['max_bytes'] = 1_000_000;
        $logger = $this->logger();
        for ($index = 0; $index < 20; $index++) $this->write($logger);
        $this->now += 86400;
        $this->write($logger);
        $this->now -= 86400;
        for ($index = 0; $index < 20; $index++) $this->write($logger);
        foreach (glob($this->root . '/logs/tycms/info/*') as $file) self::assertLessThanOrEqual(16000, filesize($file));
    }

    public function testPreviousAndNestedExceptionResponseCausesAreCapturedWithoutSecretsOrDuplicates(): void
    {
        $logger = $this->logger();
        $inner = new LogicException('secret-inner access_token=should-never-be-recorded');
        $middle = new RuntimeException('secret-outer SQL password=private', 0, $inner);
        $wrapped = new ExceptionResponse(new ApiResponse([]), new ExceptionResponse(new ApiResponse([]), $middle));
        $logger->capture($wrapped);
        $logger->capture($middle);
        $logger->capture($inner);
        $page = $logger->reader()->page([]);
        self::assertCount(1, $page['entries']);
        self::assertSame([ExceptionResponse::class, ExceptionResponse::class, RuntimeException::class, LogicException::class], array_column($page['entries'][0]['exceptions'], 'class'));
        $json = json_encode($page);
        foreach (['secret-inner', 'secret-outer', 'should-never-be-recorded', 'password=private', 'args', APP_ROOT] as $secret) self::assertStringNotContainsString($secret, $json);
        self::assertSame('UNEXPECTED_EXCEPTION', $page['entries'][0]['code']);
    }

    public function testKnownInnerReasonIsLoggedAsWarningAndKeepsItsCode(): void
    {
        $logger = $this->logger();
        $known = new LogEventException('SSO_MEMBER_REQUIRED', 'Permission MEMBER absente.', 'sso', ['space_id' => 4, 'user_id' => 12, 'code' => 'secret-code']);
        $logger->capture(new RuntimeException('unsafe outer text', 0, $known));
        $entry = $logger->reader()->page([])['entries'][0];
        self::assertSame('WARNING', $entry['level']);
        self::assertSame('SSO_MEMBER_REQUIRED', $entry['code']);
        self::assertSame(['space_id' => 4, 'user_id' => 12], $entry['context']);
    }

    public function testLongCauseChainIsBoundedAndMarkedTruncated(): void
    {
        $error = new RuntimeException('private');
        for ($index = 0; $index < 20; $index++) $error = new RuntimeException('private', 0, $error);
        $logger = $this->logger();
        $logger->capture($error);
        $entry = $logger->reader()->page([])['entries'][0];
        self::assertCount(10, $entry['exceptions']);
        self::assertTrue($entry['truncated']);
    }

    public function testUpdaterSecretInAStackFileNameIsRedacted(): void
    {
        $secret = str_repeat('a', 64);
        $record = ['exceptions' => [['class' => RuntimeException::class,
            'frames' => [['file' => 'public/update_' . $secret . '.php', 'line' => 12]]]]];
        $safe = LogRecord::project($record);
        self::assertStringNotContainsString($secret, json_encode($safe));
        self::assertSame('[external]', $safe['exceptions'][0]['frames'][0]['file']);
    }

    public function testBusinessMessageWithoutCauseDoesNotCreateAnError(): void
    {
        $logger = $this->logger();
        $logger->capture(new ExceptionResponse(new ApiResponse([])));
        self::assertSame([], $logger->reader()->page([])['entries']);
    }

    public function testControllerWarningKeepsInnerDiagnosticWithoutExposingItToVisitor(): void
    {
        $this->configuration->debug = false;
        $app = new Application($this->configuration);
        new \ReflectionProperty(Application::class, 'request')->setValue($app, new Request(uri: '/api/callback'));
        $database = $this->createMock(Database::class);
        $sql = [];
        $database->expects(self::exactly(2))->method('execute')->willReturnCallback(static function (string $query) use (&$sql): int {
            $sql[] = $query;
            return 1;
        });
        new \ReflectionProperty(Application::class, 'database')->setValue($app, $database);
        $controller = new class($app) extends BaseController {
            public function callback(): ApiResponse
            {
                $inner = new LogEventException('SSO_MEMBER_REQUIRED', 'Permission MEMBER absente.', 'sso');
                ExceptionResponse::throwWarning('Connexion impossible.', new RuntimeException('private token', 0, $inner));
            }
        };
        $route = new Route('Callback', $controller, 'callback', true, [], [], [], false, false, false, false, []);
        $response = new ApplicationRouteRunner($app)->run($route);
        self::assertSame(['START TRANSACTION', 'ROLLBACK'], $sql);
        self::assertStringNotContainsString('MEMBER', $response->body);
        self::assertStringNotContainsString('private token', $response->body);
        $entry = $app->getLogger()->reader()->page([])['entries'][0];
        self::assertSame('SSO_MEMBER_REQUIRED', $entry['code']);
        self::assertSame(3, count($entry['exceptions']));
    }

    public function testBaseApplicationItemProvidesSimpleLoggingMethods(): void
    {
        $app = new Application($this->configuration);
        $item = new class($app) extends BaseApplicationItem {
            public function exercise(): void
            {
                $this->logInfo('Information contrôlée.');
                $this->logWarning('Avertissement contrôlé.');
                $this->logError('Erreur contrôlée.');
                $this->logException(new RuntimeException('secret exception'));
            }
        };
        $item->exercise();
        $entries = $app->getLogger()->reader()->page([])['entries'];
        self::assertCount(4, $entries);
        self::assertSame(['INFO' => 1, 'WARNING' => 1, 'ERROR' => 2], array_replace(array_fill_keys(LogRecord::LEVELS, 0), array_count_values(array_column($entries, 'level'))));
    }

    public function testPaginationAcrossAllLevelsDoesNotLoseOrDuplicateEntries(): void
    {
        $this->configuration->log_levels = array_fill_keys(LogRecord::LEVELS, ['retention_days' => 90, 'max_bytes' => 1_000_000]);
        $logger = $this->logger();
        for ($index = 0; $index < 240; $index++) $logger->log(LogRecord::LEVELS[$index % 3], 'application', 'EVENT_' . $index, 'Message contrôlé.');
        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $page = $logger->reader()->page([], $cursor);
            $seen = [...$seen, ...array_column($page['entries'], 'id')];
            $cursor = $page['cursor'];
            self::assertLessThan(10, ++$pages);
            if ($pages === 1) $logger->log('INFO', 'application', 'LATE_EVENT', 'Cette entrée appartient au prochain instantané.');
        } while ($cursor !== null);
        self::assertCount(240, $seen);
        self::assertCount(240, array_unique($seen));
    }

    public function testCursorCannotBeTamperedWithOrReusedWithDifferentFilters(): void
    {
        $this->configuration->log_levels['INFO']['max_bytes'] = 1_000_000;
        $logger = $this->logger();
        for ($index = 0; $index < 120; $index++) $logger->log('INFO', 'application', 'EVENT', 'Message contrôlé.');
        $cursor = $logger->reader()->page([])['cursor'];
        self::assertNotNull($cursor);
        foreach ([[$cursor . 'x', []], [$cursor, ['code' => 'OTHER']]] as [$value, $filters]) {
            try { $logger->reader()->page($filters, $value); self::fail('Curseur invalide accepté.'); }
            catch (InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testInterruptedTailIsRepairedAndUnknownFieldsAreNeverExposed(): void
    {
        $logger = $this->logger();
        $this->write($logger);
        $file = glob($this->root . '/logs/tycms/info/*')[0];
        $record = json_decode(file_get_contents($file), true);
        $record['token'] = 'private-token';
        file_put_contents($file, json_encode($record) . "\n" . '{"incomplete":');
        $this->write($logger);
        $page = $logger->reader()->page(['levels' => ['INFO']]);
        self::assertCount(2, $page['entries']);
        self::assertSame(0, $page['corrupt_lines']);
        self::assertStringNotContainsString('private-token', json_encode($page));
        self::assertSame(['LOG_TAIL_REPAIRED'], array_column($logger->reader()->page(['levels' => ['WARNING']])['entries'], 'code'));
    }

    public function testSymlinkCannotRedirectAWriteOrReader(): void
    {
        $logger = $this->logger();
        $logger->status();
        $outside = $this->root . '/keep.txt';
        file_put_contents($outside, 'private contents');
        symlink($outside, $this->root . '/logs/tycms/info/tycms-20261115-000001.jsonl');
        $this->write($logger);
        self::assertSame('private contents', file_get_contents($outside));
        self::assertFalse($logger->status()['available']);
    }

    public function testPublicLogPathAndInvalidConfigurationAreRejectedWithoutBreakingCaller(): void
    {
        $this->configuration->log_path = APP_ROOT . '/public/logging-must-not-be-created';
        $logger = $this->logger();
        $this->write($logger);
        self::assertFalse($logger->status()['available']);
        self::assertDirectoryDoesNotExist($this->configuration->log_path);
        $this->configuration->log_levels['INFO']['max_bytes'] = -1;
        $invalid = $this->logger();
        $this->write($invalid);
        self::assertFalse($invalid->status()['available']);
    }

    public function testForeignFilesAreNeverPurged(): void
    {
        $logger = $this->logger();
        $logger->status();
        $file = $this->root . '/logs/tycms/info/foreign.txt';
        file_put_contents($file, 'keep');
        $this->now += 100 * 86400;
        $logger->status();
        self::assertSame('keep', file_get_contents($file));
    }

    public function testReadBudgetStillAllowsProgressWhenNoEntryMatches(): void
    {
        $this->configuration->log_max_file_bytes = 10_000_000;
        $this->configuration->log_levels = array_fill_keys(LogRecord::LEVELS, ['retention_days' => 90, 'max_bytes' => 50_000_000]);
        $logger = $this->logger();
        $logger->log('INFO', 'application', 'INITIAL_EVENT', 'Message contrôlé.');
        $file = glob($this->root . '/logs/tycms/info/*')[0];
        $line = file_get_contents($file);
        file_put_contents($file, str_repeat($line, 1500));
        $page = $logger->reader()->page(['code' => 'NO_MATCH']);
        self::assertSame([], $page['entries']);
        self::assertNotNull($page['cursor']);
        $next = $logger->reader()->page(['code' => 'NO_MATCH'], $page['cursor']);
        self::assertSame([], $next['entries']);
        self::assertNotSame($page['cursor'], $next['cursor']);
    }

    public function testLockWaitIsBoundedAndDoesNotBreakBusinessCaller(): void
    {
        $logger = $this->logger();
        $logger->status();
        $lock = fopen($this->root . '/logs/tycms/.lock', 'r+b');
        flock($lock, LOCK_EX);
        $start = microtime(true);
        try { $this->write($logger); }
        finally { flock($lock, LOCK_UN); fclose($lock); }
        self::assertLessThan(0.5, microtime(true) - $start);
        self::assertSame([], $logger->reader()->page([])['entries']);
    }

    public function testConcurrentWritersKeepValidLinesAndRotateTogether(): void
    {
        $worker = $this->root . '/worker.php';
        file_put_contents($worker, '<?php require ' . var_export(APP_ROOT . '/tests/bootstrap.php', true) . ';' . <<<'PHP'
            $config = new Configuration();
            $config->log_path = $argv[1];
            $config->log_max_file_bytes = 16000;
            $logger = new YTassy\Tycms\Logging\Logger($config, APP_ROOT);
            for ($index = 0; $index < 30; $index++) {
                $logger->log('INFO', 'application', 'CONCURRENT_EVENT', str_repeat('Test. ', 200));
            }
            PHP);
        $processes = [];
        for ($index = 0; $index < 3; $index++) {
            $pipes = [];
            $process = proc_open([PHP_BINARY, $worker, $this->configuration->log_path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            foreach ($pipes as $pipe) fclose($pipe);
            self::assertSame(0, proc_close($process), $stdout . $stderr);

            // Le verrou du journal a une attente bornée : sous forte contention, une écriture peut
            // être abandonnée et signalée par le secours. Une entrée manquante est admise ; ce test
            // vérifie que toute sortie d'erreur est bien ce signal, et qu'aucune ligne n'est corrompue.
            foreach (preg_split('/\R/', trim($stderr)) as $line) {
                if ($line === '') continue;
                self::assertMatchesRegularExpression('/\A\[Tycms\] LOG_WRITE_FAILED request_id=[a-f0-9]{32}\z/', $line,
                    'Sortie d’erreur inattendue du worker : ' . $line);
            }
        }

        // Relire le journal produit : chaque ligne conservée est exploitable, sans doublon.
        $this->configuration->log_levels['INFO']['max_bytes'] = 50_000_000;
        $logger = new Logger($this->configuration, APP_ROOT);
        $page = $logger->reader()->page([]);
        $entries = $page['entries'];
        $ids = array_column($entries, 'id');
        self::assertSame(count($ids), count(array_unique($ids)), 'Identifiant de journal dupliqué.');
        self::assertGreaterThan(0, count($entries), 'Aucune entrée de journal conservée.');
        foreach ($entries as $entry) {
            self::assertSame('CONCURRENT_EVENT', $entry['code'], 'Ligne de journal corrompue.');
            self::assertSame(strlen(str_repeat('Test. ', 200)), strlen($entry['message']), 'Message de journal tronqué ou corrompu.');
        }
        self::assertGreaterThan(1, $logger->status()['levels']['INFO']['files'], 'Rotation absente.');
    }

    public function testFatalAfterLoggerStartupIsRecordedWithoutItsRawMessage(): void
    {
        $worker = $this->root . '/fatal.php';
        file_put_contents($worker, '<?php require ' . var_export(APP_ROOT . '/tests/bootstrap.php', true) . ';' . <<<'PHP'
            $config = new Configuration();
            $config->log_path = $argv[1];
            $logger = new YTassy\Tycms\Logging\Logger($config, APP_ROOT);
            $logger->registerShutdown();
            nonExistingLoggingTestFunction();
            PHP);
        $pipes = [];
        $process = proc_open([PHP_BINARY, $worker, $this->configuration->log_path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        foreach ($pipes as $pipe) { stream_get_contents($pipe); fclose($pipe); }
        self::assertNotSame(0, proc_close($process));
        $logger = new Logger($this->configuration, APP_ROOT);
        $page = $logger->reader()->page([]);
        self::assertSame(['PHP_FATAL'], array_column($page['entries'], 'code'));
        self::assertStringNotContainsString('nonExistingLoggingTestFunction', json_encode($page));
    }
}
