<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use RuntimeException;
use ZipArchive;
use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate\ArchiveInspector;
use YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate\ServerUnavailableException;
use YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate\SystemUpdateHelper;
use YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate\SystemUpdatePackageHelper;
use YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate\SystemUpdatePreparationHelper;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class SystemUpdatePackageTest extends FrameworkTestCase
{
    private string $root;
    private string $secret;
    private string $public;
    private const FIRST = 'https://first.example.test/index.php';
    private const SECOND = 'https://second.example.test/index.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/tycms-package-test-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
        mkdir($this->root . '/site', 0700);
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->public = base64_encode(sodium_crypto_sign_publickey($pair));
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        parent::tearDown();
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (new \FilesystemIterator($path) as $entry) {
                $this->remove($entry->getPathname());
            }
            rmdir($path);
        } else {
            unlink($path);
        }
    }

    private function zip(array $extra = [], string $version = '1.1.0'): string
    {
        $path = $this->root . '/fixture-' . bin2hex(random_bytes(4)) . '.zip';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE));
        $release = 'releases/v1_1_0/';
        foreach ([$release . 'index.php', $release . 'configuration.base.php', $release . 'composer.json',
            $release . 'composer.lock', $release . 'vendor/autoload.php', 'public/index.php',
            'public/.htaccess', 'updater/index.php', 'var/releases-states.json'] as $name) {
            $zip->addFromString($name, 'fixture non exécutable');
        }
        $zip->addFromString($release . 'version.php', "<?php\ndefine('APP_VERSION', '$version');\ndefine('APP_VERSION_STRING', '" . str_replace('.', '_', $version) . "');\n");
        foreach ($extra as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        return $path;
    }

    private function envelope(string $zip, array $overrides = []): string
    {
        $manifest = array_replace(['schema_version' => 1, 'type' => 'system', 'version' => '1.1.0',
            'version_string' => '1_1_0', 'source_versions' => ['1.0.0'], 'php_min' => '8.4.1',
            'php_max_exclusive' => '8.6.0', 'archive' => ['filename' => 'tycms-1_1_0.zip',
                'bytes' => filesize($zip), 'sha256' => hash_file('sha256', $zip)]], $overrides);
        $payload = json_encode($manifest, JSON_THROW_ON_ERROR);

        return json_encode(['key_id' => 'test', 'payload' => base64_encode($payload),
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, $this->secret))], JSON_THROW_ON_ERROR);
    }

    private function helper(string $envelope, array $responses): SystemUpdatePackageHelper
    {
        $app = $this->createApplication();
        $app->getConf()->update_servers = [self::FIRST, self::SECOND];
        $app->getConf()->update_trusted_public_keys = ['test' => $this->public];
        $catalogue = new class($app, $envelope) extends SystemUpdateHelper {
            public int $calls = 0;
            public function __construct(Application $app, private string $envelope) { parent::__construct($app); }
            protected function fetch(string $url, int $maximumBytes): string
            {
                ++$this->calls;
                return $this->envelope;
            }
        };

        return new class($app, $catalogue, $this->root . '/site', $responses) extends SystemUpdatePackageHelper {
            public array $requests = [];
            public function __construct(Application $app, public SystemUpdateHelper $catalogue,
                private string $root, private array $responses) { parent::__construct($app); }
            protected function installationRoot(): string { return $this->root; }
            public function getSharedExtensionsClass(string $className): BaseApplicationItem
            {
                if ($className === SystemUpdateHelper::class) return $this->catalogue;
                throw new \LogicException('Service imprévu');
            }
            protected function transfer(string $url, mixed $stream, int $expectedBytes, int $timeoutMs): void
            {
                $this->requests[] = $url;
                if ($this->responses === []) throw new \LogicException('Téléchargement imprévu');
                $response = array_shift($this->responses);
                if ($response instanceof \Throwable) {
                    fwrite($stream, 'téléchargement partiel');
                    throw $response;
                }
                fwrite($stream, $response);
            }
        };
    }

    private function download(SystemUpdatePackageHelper $helper, string $zip): array
    {
        return $helper->download('1.0.0', '8.5.0', '1.1.0', hash_file('sha256', $zip));
    }

    private function assertNoPackage(): void
    {
        self::assertSame([], glob($this->root . '/site/var/updates/packages/v*') ?: []);
        self::assertSame([], glob($this->root . '/site/var/updates/packages/.incoming-*') ?: []);
    }

    public function testDownloadPublishesOnlyAnAuthenticatedArchiveAndRechecksTheCache(): void
    {
        $zip = $this->zip();
        $helper = $this->helper($this->envelope($zip), [file_get_contents($zip)]);
        $result = $this->download($helper, $zip);
        $directory = $this->root . '/site/var/updates/packages/' . $result['package_id'];
        self::assertFalse($result['reused']);
        self::assertSame(hash_file('sha256', $zip), hash_file('sha256', $directory . '/package.zip'));
        self::assertFileExists($directory . '/manifest.json');
        self::assertSame(0600, fileperms($directory . '/package.zip') & 0777);
        self::assertSame(0700, fileperms($directory) & 0777);
        self::assertFileDoesNotExist($this->root . '/site/releases');
        self::assertFileDoesNotExist($this->root . '/site/public');
        self::assertFileDoesNotExist($this->root . '/site/var/releases-states.json');
        self::assertTrue($this->download($helper, $zip)['reused']);
        self::assertCount(1, $helper->requests);
        self::assertSame(2, $helper->catalogue->calls);
    }

    public function testTransportInterruptionResetsThePartialFileBeforeTryingTheNextServer(): void
    {
        $zip = $this->zip();
        $helper = $this->helper($this->envelope($zip), [new ServerUnavailableException(), file_get_contents($zip)]);
        $result = $this->download($helper, $zip);
        self::assertCount(1, $result['failures']);
        self::assertSame([self::FIRST . '?action=package&version=1.1.0', self::SECOND . '?action=package&version=1.1.0'], $helper->requests);
    }

    public function testHashFailureStopsWithoutFallbackAndCleansTheAttempt(): void
    {
        $zip = $this->zip();
        $body = file_get_contents($zip);
        $body[10] = chr(ord($body[10]) ^ 1);
        $helper = $this->helper($this->envelope($zip), [$body]);
        try {
            $this->download($helper, $zip);
            self::fail('ZIP altéré accepté');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Empreinte', $exception->getMessage());
        }
        self::assertCount(1, $helper->requests);
        $this->assertNoPackage();
    }

    public function testTruncatedArchiveIsNotCached(): void
    {
        $zip = $this->zip();
        $helper = $this->helper($this->envelope($zip), [substr(file_get_contents($zip), 0, -10)]);
        try {
            $this->download($helper, $zip);
            self::fail('ZIP tronqué accepté');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Taille', $exception->getMessage());
        }
        $this->assertNoPackage();
    }

    public function testTotalOutageDoesNotLeaveAnIncomingPackage(): void
    {
        $zip = $this->zip();
        $helper = $this->helper($this->envelope($zip), [new ServerUnavailableException(), new ServerUnavailableException()]);
        try {
            $this->download($helper, $zip);
            self::fail('Panne masquée');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Aucun serveur', $exception->getMessage());
        }
        $this->assertNoPackage();
    }

    public function testChangedExpectedIdentityFailsBeforeAnyCacheOrDownload(): void
    {
        $zip = $this->zip();
        $helper = $this->helper($this->envelope($zip), []);
        try {
            $helper->download('1.0.0', '8.5.0', '1.1.0', str_repeat('a', 64));
            self::fail('Identité modifiée acceptée');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('a changé', $exception->getMessage());
        }
        self::assertFileDoesNotExist($this->root . '/site/var');
        self::assertSame([], $helper->requests);
    }

    public function testSignatureAndCompatibilityAreRecheckedBeforeAnyDownload(): void
    {
        $zip = $this->zip();
        $unknown = json_decode($this->envelope($zip), true);
        $unknown['key_id'] = 'inconnue';
        foreach ([json_encode($unknown), $this->envelope($zip, ['source_versions' => ['1.0.1']]),
            $this->envelope($zip, ['php_min' => '8.5.1'])] as $envelope) {
            $helper = $this->helper($envelope, []);
            try {
                $this->download($helper, $zip);
                self::fail('Manifeste non admissible accepté');
            } catch (RuntimeException) {
                self::assertSame([], $helper->requests);
                self::assertFileDoesNotExist($this->root . '/site/var');
            }
        }
    }

    public function testWrongVersionInsideAnAuthenticatedZipIsRejected(): void
    {
        $zip = $this->zip(version: '1.2.0');
        $helper = $this->helper($this->envelope($zip), [file_get_contents($zip)]);
        try {
            $this->download($helper, $zip);
            self::fail('Version interne erronée acceptée');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Version du ZIP', $exception->getMessage());
        }
        $this->assertNoPackage();
    }

    public function testAnAuthenticatedFileThatIsNotAZipIsRejected(): void
    {
        $path = $this->root . '/not-a-zip';
        file_put_contents($path, 'signature valide mais archive invalide');
        $helper = $this->helper($this->envelope($path), [file_get_contents($path)]);
        try {
            $this->download($helper, $path);
            self::fail('Fichier non ZIP accepté');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('ZIP système invalide', $exception->getMessage());
        }
        $this->assertNoPackage();
    }

    public function testTraversalSymlinksAndFileDirectoryCollisionsAreRejectedWithoutExtraction(): void
    {
        foreach ([['../outside' => 'interdit'], ['public//ambiguous' => 'interdit'],
            ['public/' . str_repeat('nested/', 64) . 'deep' => 'interdit'],
            ['public/collision' => 'fichier', 'public/collision/child' => 'interdit'],
            ['releases/v1_1_0/configuration.php' => 'secret'], ['releases/v2_0_0/index.php' => 'interdit']] as $extra) {
            $zip = $this->zip($extra);
            try {
                ArchiveInspector::inspect($zip, '1.1.0');
                self::fail('Chemin dangereux accepté');
            } catch (RuntimeException) {
                self::assertFileDoesNotExist($this->root . '/outside');
            }
        }
        $path = $this->zip(['public/link' => '../outside']);
        $zip = new ZipArchive();
        $zip->open($path);
        $zip->setExternalAttributesName('public/link', ZipArchive::OPSYS_UNIX, 0120777 << 16);
        $zip->close();
        $this->expectException(RuntimeException::class);
        ArchiveInspector::inspect($path, '1.1.0');
    }

    public function testAnArchiveWithoutTheConfigurationBaseIsRejected(): void
    {
        $path = $this->zip();
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path));
        self::assertTrue($zip->deleteName('releases/v1_1_0/configuration.base.php'));
        $zip->close();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Archive système incomplète.');
        ArchiveInspector::inspect($path, '1.1.0');
    }

    public function testDirectorySymlinksAreNeverFollowed(): void
    {
        mkdir($this->root . '/outside', 0700);
        symlink($this->root . '/outside', $this->root . '/site/var');
        $zip = $this->zip();
        $helper = $this->helper($this->envelope($zip), []);
        try {
            $this->download($helper, $zip);
            self::fail('Lien du cache suivi');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('lien symbolique', $exception->getMessage());
        }
        self::assertSame([], glob($this->root . '/outside/*'));
    }

    public function testAnAlteredCacheIsNotReusedOrSilentlyOverwritten(): void
    {
        $zip = $this->zip();
        $helper = $this->helper($this->envelope($zip), [file_get_contents($zip)]);
        $receipt = $this->download($helper, $zip);
        $path = $this->root . '/site/var/updates/packages/' . $receipt['package_id'] . '/package.zip';
        file_put_contents($path, 'altéré');
        try {
            $this->download($helper, $zip);
            self::fail('Cache altéré accepté');
        } catch (RuntimeException) {
            self::assertSame('altéré', file_get_contents($path));
        }
        self::assertCount(1, $helper->requests);
    }

    public function testConcurrentDownloadIsRejectedWithoutWaiting(): void
    {
        mkdir($this->root . '/site/var/updates/packages', 0700, true);
        $lock = fopen($this->root . '/site/var/updates/packages/.download.lock', 'c+b');
        flock($lock, LOCK_EX);
        try {
            $zip = $this->zip();
            $helper = $this->helper($this->envelope($zip), []);
            $this->expectException(RuntimeException::class);
            $this->download($helper, $zip);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testStarSourceMarkerAllowsDownloadFromAnyOlderSource(): void
    {
        $zip = $this->zip();
        $helper = $this->helper($this->envelope($zip, ['source_versions' => '*']), [file_get_contents($zip)]);
        $result = $helper->download('0.9.0', '8.5.0', '1.1.0', hash_file('sha256', $zip));
        self::assertSame('1.1.0', $result['version']);
        self::assertFalse($result['reused']);
    }

    public function testStarSourceMarkerStillRequiresAStrictlyNewerTarget(): void
    {
        $zip = $this->zip();
        $helper = $this->helper($this->envelope($zip, ['source_versions' => '*']), []);
        $this->expectException(RuntimeException::class);
        $helper->download('1.1.0', '8.5.0', '1.1.0', hash_file('sha256', $zip));
    }

    public function testDevDownloadsAnIncompatibleSourceWithoutBypassingZipControls(): void
    {
        $zip = $this->zip();
        $helper = $this->helper($this->envelope($zip, ['source_versions' => []]), [file_get_contents($zip)]);
        $result = $helper->download('1.1.0', '8.5.0', '1.1.0', hash_file('sha256', $zip), true);
        self::assertSame('1.1.0', $result['version']);
        self::assertFalse($result['reused']);
    }

    public function testDevStillRejectsAnAlteredZipAndAnIncompatiblePhp(): void
    {
        $zip = $this->zip();
        $body = file_get_contents($zip);
        $body[10] = chr(ord($body[10]) ^ 1);
        $helper = $this->helper($this->envelope($zip, ['source_versions' => []]), [$body]);
        try {
            $helper->download('1.1.0', '8.5.0', '1.1.0', hash_file('sha256', $zip), true);
            self::fail('ZIP altéré accepté en DEV');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Empreinte', $exception->getMessage());
        }
        $this->assertNoPackage();

        $helper = $this->helper($this->envelope($zip, ['source_versions' => []]), []);
        $this->expectException(RuntimeException::class);
        $helper->download('1.1.0', '8.6.0', '1.1.0', hash_file('sha256', $zip), true);
    }
    private function preparation(SystemUpdatePackageHelper $package, bool $dev): SystemUpdatePreparationHelper
    {
        $site = $this->root . '/site';
        if (!is_dir($site . '/public')) {
            mkdir($site . '/public', 0755);
            mkdir($site . '/updater', 0700);
            file_put_contents($site . '/updater/index.php', "<?php echo 'developed';");
            file_put_contents($site . '/public/index.php', 'bootstrap conservé');
            file_put_contents($site . '/var/releases-states.json', json_encode([
                'schema_version' => 1, 'active_release' => 'vdev', 'available_update' => null, 'active_operation' => null]));
        }
        $app = $package->getApp();
        $app->getConf()->dev = $dev;
        return new class($app, $site, $package) extends SystemUpdatePreparationHelper {
            public function __construct(Application $app, private string $root, private SystemUpdatePackageHelper $package)
            {
                parent::__construct($app);
            }
            protected function installationRoot(): string { return $this->root; }
            public function getSharedExtensionsClass(string $className): BaseApplicationItem
            {
                if ($className === SystemUpdatePackageHelper::class) return $this->package;
                throw new \LogicException('Service imprévu');
            }
        };
    }

    public function testPreparationExtractsAndInstallsTheEngineWithoutChangingTheActiveRelease(): void
    {
        $zip = $this->zip(['public/assets_updater/app.js' => 'asset signé', 'updater/lib.php' => 'moteur signé']);
        $package = $this->helper($this->envelope($zip), [file_get_contents($zip)]);
        $download = $this->download($package, $zip);
        $helper = $this->preparation($package, false);
        $result = $helper->prepare('1.0.0', '8.5.0', '1.1.0', hash_file('sha256', $zip));
        $site = $this->root . '/site';
        self::assertMatchesRegularExpression('~^/update_[a-f0-9]{64}\.php$~', $result['url']);
        self::assertSame('moteur signé', file_get_contents($site . '/updater/lib.php'));
        self::assertSame('asset signé', file_get_contents($site . '/public/assets_updater/app.js'));
        self::assertSame(0644, fileperms($site . '/public/assets_updater/app.js') & 0777);
        self::assertSame('bootstrap conservé', file_get_contents($site . '/public/index.php'));
        self::assertFileExists($site . '/var/updates/packages/' . $download['package_id'] . '/files/releases/v1_1_0/vendor/autoload.php');
        self::assertFileDoesNotExist($site . '/releases/v1_1_0');
        $state = json_decode(file_get_contents($site . '/var/releases-states.json'), true);
        self::assertSame('vdev', $state['active_release']);
        self::assertSame('prepared', $state['active_operation']['status']);
        self::assertSame($result['url'], $helper->prepare('1.0.0', '8.5.0', '1.1.0', hash_file('sha256', $zip))['url']);
        self::assertSame([], glob($site . '/var/updates/.prepare-*'));
        self::assertCount(1, $package->requests);
    }

    public function testDevPreservesItsEngineAndAssetsAndTheLinkOnlyLoadsTheEngine(): void
    {
        $zip = $this->zip(['public/assets_updater/app.js' => 'asset ZIP']);
        $package = $this->helper($this->envelope($zip), [file_get_contents($zip)]);
        $this->download($package, $zip);
        $helper = $this->preparation($package, true);
        $site = $this->root . '/site';
        mkdir($site . '/public/assets_updater');
        file_put_contents($site . '/public/assets_updater/app.js', 'asset DEV');
        $result = $helper->prepare('1.0.0', '8.5.0', '1.1.0', hash_file('sha256', $zip));
        self::assertTrue($result['development']);
        self::assertSame('asset DEV', file_get_contents($site . '/public/assets_updater/app.js'));
        self::assertSame('asset ZIP', file_get_contents($site . '/public/assets_updater_test/app.js'));
        self::assertSame('fixture non exécutable', file_get_contents($site . '/updater_test/index.php'));
        self::assertSame("<?php\n\nrequire_once dirname(__DIR__) . '/updater/index.php';", file_get_contents($site . '/public' . $result['url']));
        self::assertSame('developed', $this->executeEntrypoint($site . '/public' . $result['url']));
    }

    private function executeEntrypoint(string $path): string
    {
        $process = proc_open([PHP_BINARY, $path], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertSame('', $error);
        return $output;
    }

    public function testPreparationRejectsAlteredCacheBeforeExtractingOrPublishingALink(): void
    {
        $zip = $this->zip();
        $package = $this->helper($this->envelope($zip), [file_get_contents($zip)]);
        $receipt = $this->download($package, $zip);
        $helper = $this->preparation($package, false);
        $site = $this->root . '/site';
        file_put_contents($site . '/var/updates/packages/' . $receipt['package_id'] . '/package.zip', 'altéré');
        try {
            $helper->prepare('1.0.0', '8.5.0', '1.1.0', hash_file('sha256', $zip));
            self::fail('Cache altéré extrait');
        } catch (RuntimeException) {
            self::assertSame([], glob($site . '/public/update_*.php'));
            self::assertSame("<?php echo 'developed';", file_get_contents($site . '/updater/index.php'));
            self::assertNull(json_decode(file_get_contents($site . '/var/releases-states.json'), true)['active_operation']);
        }
    }

    public function testPreparationRejectsASymlinkTargetAndConcurrentPreparation(): void
    {
        $zip = $this->zip();
        $package = $this->helper($this->envelope($zip), [file_get_contents($zip)]);
        $this->download($package, $zip);
        $helper = $this->preparation($package, true);
        $site = $this->root . '/site';
        symlink($site . '/updater', $site . '/updater_test');
        try {
            $helper->prepare('1.0.0', '8.5.0', '1.1.0', hash_file('sha256', $zip));
            self::fail('Lien symbolique accepté');
        } catch (RuntimeException) {
            self::assertSame([], glob($site . '/public/update_*.php'));
            self::assertSame([], glob($site . '/var/updates/.prepare-*'));
        }
        unlink($site . '/updater_test');
        $lock = fopen($site . '/var/updates/.prepare.lock', 'c+b');
        flock($lock, LOCK_EX);
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('déjà en cours');
            $helper->prepare('1.0.0', '8.5.0', '1.1.0', hash_file('sha256', $zip));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function testAnExistingOperationPreventsReplacingTheEngine(): void
    {
        $zip = $this->zip();
        $package = $this->helper($this->envelope($zip), [file_get_contents($zip)]);
        $this->download($package, $zip);
        $helper = $this->preparation($package, false);
        $path = $this->root . '/site/var/releases-states.json';
        $state = json_decode(file_get_contents($path), true);
        $state['active_operation'] = ['status' => 'preparing'];
        file_put_contents($path, json_encode($state));
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('existe déjà');
        $helper->prepare('1.0.0', '8.5.0', '1.1.0', hash_file('sha256', $zip));
    }

}
