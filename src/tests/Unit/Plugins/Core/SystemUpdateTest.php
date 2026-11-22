<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use RuntimeException;
use YTassy\Tycms\Application;
use YTassy\Tycms\BaseApplicationItem;
use YTassy\Tycms\CoreExts\Core\Helper\Acl\AclHelper;
use YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate\ServerUnavailableException;
use YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate\SystemUpdateHelper;
use YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate\SystemUpdatePackageHelper;
use YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate\SystemUpdatePreparationHelper;
use YTassy\Tycms\CoreExts\Core\WF\WFSystemUpdate;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class SystemUpdateTest extends FrameworkTestCase
{
    private string $secret;
    private string $public;
    private const FIRST = 'https://first.example.test/index.php';
    private const SECOND = 'https://second.example.test/index.php';

    protected function setUp(): void
    {
        parent::setUp();
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->public = base64_encode(sodium_crypto_sign_publickey($pair));
    }

    private function envelope(string $version = '1.1.0', array|string $sources = ['1.0.0'], ?string $notes = null): array
    {
        $manifest = ['schema_version' => 1, 'type' => 'system', 'version' => $version,
            'version_string' => str_replace('.', '_', $version), 'source_versions' => $sources,
            'php_min' => '8.4.1', 'php_max_exclusive' => '8.6.0',
            'archive' => ['filename' => 'tycms-' . str_replace('.', '_', $version) . '.zip',
                'bytes' => 100, 'sha256' => str_repeat('a', 64)]];
        if ($notes !== null) {
            $manifest['release_notes'] = ['filename' => 'tycms-' . str_replace('.', '_', $version) . '.md',
                'bytes' => strlen($notes), 'sha256' => hash('sha256', $notes)];
        }

        $payload = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return ['key_id' => 'test', 'payload' => base64_encode($payload),
            'signature' => base64_encode(sodium_crypto_sign_detached($payload, $this->secret))];
    }

    private function catalogue(array $envelopes): string
    {
        return json_encode(['schema_version' => 1, 'releases' => $envelopes], JSON_THROW_ON_ERROR);
    }

    private function helper(array $responses): SystemUpdateHelper
    {
        $app = $this->createApplication();
        $app->getConf()->update_servers = [self::FIRST, self::SECOND];
        $app->getConf()->update_trusted_public_keys = ['test' => $this->public];

        return new class($app, $responses) extends SystemUpdateHelper {
            public array $requests = [];

            public function __construct(Application $app, private array $responses)
            {
                parent::__construct($app);
            }

            protected function fetch(string $url, int $maximumBytes): string
            {
                $this->requests[] = $url;
                if ($this->responses === []) {
                    throw new \LogicException('Requête réseau imprévue : ' . $url);
                }
                $response = array_shift($this->responses);
                if ($response instanceof \Throwable) {
                    throw $response;
                }
                return $response;
            }
        };
    }

    public function testServerSignaturesAndNotesAreVerifiedAndLatestCompatibleVersionIsSelected(): void
    {
        $notes = "# Version 1.2.0\n<script>contenu à afficher comme texte</script>";
        $helper = $this->helper([$this->catalogue([
            $this->envelope('1.2.0', notes: $notes), $this->envelope(),
            $this->envelope('1.3.0', ['1.1.0']), $this->envelope('1.0.0', []),
        ]), $notes]);
        $result = $helper->check('1.0.0', '8.4.27');

        self::assertSame('1.2.0', $result['available_update']['version']);
        self::assertSame(['1.2.0', '1.1.0'], $result['compatible_versions']);
        self::assertSame($notes, $result['notes']);
        self::assertSame([self::FIRST . '?action=releases', self::FIRST . '?action=notes&version=1.2.0'], $helper->requests);
    }

    public function testOnlyTransportFailureAllowsTheNextCatalogue(): void
    {
        $helper = $this->helper([new ServerUnavailableException(), $this->catalogue([$this->envelope()])]);
        $result = $helper->check('1.0.0', '8.5.0');

        self::assertSame(self::SECOND, $result['server']);
        self::assertCount(1, $result['failures']);
        self::assertSame([self::FIRST . '?action=releases', self::SECOND . '?action=releases'], $helper->requests);
    }

    public function testAnEmptyValidCatalogueDoesNotFallBack(): void
    {
        $helper = $this->helper([$this->catalogue([])]);
        self::assertNull($helper->check('1.0.0', '8.4.1')['available_update']);
        self::assertCount(1, $helper->requests);
    }

    public function testSourceAndBothPhpBoundariesAreRespected(): void
    {
        foreach ([['1.0.0', '8.4.1', true], ['1.0.0', '8.4.0', false],
            ['1.0.0', '8.6.0', false], ['1.0.1', '8.5.0', false], ['1.1.0', '8.5.0', false]] as [$source, $php, $expected]) {
            $helper = $this->helper([$this->catalogue([$this->envelope()])]);
            self::assertSame($expected, $helper->check($source, $php)['available_update'] !== null);
        }
    }

    public function testStarSourceMarkerAcceptsAnyOlderVersionButNotTheTarget(): void
    {
        foreach (['0.9.0', '1.0.0', '1.1.0'] as $source) {
            $helper = $this->helper([$this->catalogue([$this->envelope('1.2.0', '*')])]);
            self::assertSame('1.2.0', $helper->check($source, '8.5.0')['available_update']['version']);
        }

        // La cible reste strictement supérieure à la source, même avec « * ».
        $helper = $this->helper([$this->catalogue([$this->envelope('1.2.0', '*')])]);
        self::assertNull($helper->check('1.2.0', '8.5.0')['available_update']);
    }

    public function testSignedManifestRejectsStarInsideAList(): void
    {
        $helper = $this->helper([$this->catalogue([$this->envelope('1.2.0', ['*'])])]);
        $this->expectException(RuntimeException::class);
        $helper->check('1.0.0', '8.5.0');
    }

    public function testInvalidSignaturesAndUnknownKeysStopEvenForAnIncompatibleRelease(): void
    {
        $changed = $this->envelope('1.0.0', []);
        $changed['payload'] = base64_encode(base64_decode($changed['payload']) . ' ');
        $unknown = $this->envelope();
        $unknown['key_id'] = 'unknown';
        foreach ([$changed, $unknown] as $envelope) {
            $helper = $this->helper([$this->catalogue([$envelope])]);
            try {
                $helper->check('1.0.0', '8.5.0');
                self::fail('Signature non reconnue acceptée.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('sans consulter un autre serveur', $exception->getMessage());
            }
            self::assertCount(1, $helper->requests);
        }
    }

    public function testMalformedAndDuplicateCataloguesStopWithoutFallback(): void
    {
        foreach (['<html>Erreur</html>', '{"schema_version":2,"releases":[]}',
            $this->catalogue([$this->envelope(), $this->envelope()])] as $catalogue) {
            $helper = $this->helper([$catalogue]);
            try {
                $helper->check('1.0.0', '8.5.0');
                self::fail('Catalogue invalide accepté.');
            } catch (RuntimeException) {
                self::assertCount(1, $helper->requests);
            }
        }
    }

    public function testSignedButInvalidCompatibilityIsNotTrusted(): void
    {
        $envelope = $this->envelope();
        $manifest = json_decode(base64_decode($envelope['payload']), true, 16, JSON_THROW_ON_ERROR);
        $manifest['source_versions'] = ['1.1.0'];
        $payload = json_encode($manifest, JSON_THROW_ON_ERROR);
        $envelope['payload'] = base64_encode($payload);
        $envelope['signature'] = base64_encode(sodium_crypto_sign_detached($payload, $this->secret));
        $helper = $this->helper([$this->catalogue([$envelope])]);
        $this->expectException(RuntimeException::class);
        $helper->check('1.0.0', '8.5.0');
    }

    public function testMissingConfigurationIsReportedWithoutAnyNetwork(): void
    {
        $helper = $this->helper([]);
        unset($helper->getApp()->getConf()->update_servers);
        // Le contexte interroge aussi la disponibilité du nettoyage, sans préparer de fichiers.
        $preparation = $this->createStub(SystemUpdatePreparationHelper::class);
        $preparation->method('cleanupAvailable')->willReturn(false);
        $context = $this->workflow(true, 'dev', $helper, preparation: $preparation)->context();
        self::assertNotNull($context['configuration_error']);
        self::assertSame([], $helper->requests);
    }

    public function testNotesFallbackKeepsTheSignedIdentity(): void
    {
        $notes = '# Notes authentifiées';
        $helper = $this->helper([$this->catalogue([$this->envelope(notes: $notes)]), new ServerUnavailableException(), $notes]);
        self::assertSame($notes, $helper->check('1.0.0', '8.5.0')['notes']);
        self::assertSame(self::SECOND . '?action=notes&version=1.1.0', $helper->requests[2]);
    }

    public function testTamperedNotesStopWithoutAnotherServer(): void
    {
        $helper = $this->helper([$this->catalogue([$this->envelope(notes: '# Notes')]), '# Faux!']);
        try {
            $helper->check('1.0.0', '8.5.0');
            self::fail('Notes altérées acceptées.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('altérées', $exception->getMessage());
        }
        self::assertCount(2, $helper->requests);
    }

    public function testInvalidLocalSettingsAreRejectedBeforeTheNetwork(): void
    {
        $helper = $this->helper([]);
        $helper->getApp()->getConf()->update_servers = ['http://127.0.0.1/index.php'];
        $this->expectException(RuntimeException::class);
        $helper->check('1.0.0', '8.5.0');
    }

    private function workflow(bool $root, string $version, SystemUpdateHelper $helper,
        ?SystemUpdatePackageHelper $package = null, ?SystemUpdatePreparationHelper $preparation = null): WFSystemUpdate
    {
        $acl = $this->createStub(AclHelper::class);
        $acl->method('isRoot')->willReturn($root);

        return new class($helper->getApp(), $acl, $helper, $version, $package, $preparation) extends WFSystemUpdate {
            public function __construct(Application $app, private AclHelper $acl,
                private SystemUpdateHelper $helper, private string $version, private ?SystemUpdatePackageHelper $package,
                private ?SystemUpdatePreparationHelper $preparation)
            {
                parent::__construct($app);
            }

            protected function installedVersion(): string { return $this->version; }

            public function getSharedExtensionsClass(string $className): BaseApplicationItem
            {
                return match ($className) {
                    AclHelper::class => $this->acl,
                    SystemUpdateHelper::class => $this->helper,
                    SystemUpdatePackageHelper::class => $this->package ?? throw new \LogicException('Téléchargement imprévu'),
                    SystemUpdatePreparationHelper::class => $this->preparation ?? throw new \LogicException('Préparation imprévue'),
                    default => throw new \LogicException('Service imprévu'),
                };
            }
        };
    }

    public function testWorkflowActionsRequireRootBeforeAnyNetwork(): void
    {
        foreach (['context', 'check', 'download', 'prepare', 'reset'] as $action) {
            $helper = $this->helper([]);
            try {
                $workflow = $this->workflow(false, '1.0.0', $helper);
                if (in_array($action, ['download', 'prepare'], true)) {
                    $workflow->$action('1.1.0', str_repeat('a', 64));
                } else {
                    $workflow->$action();
                }
                self::fail('Lecture autorisée sans ROOT.');
            } catch (AccessDeniedException) {
                self::assertSame([], $helper->requests);
            }
        }
    }

    public function testSimulationIsExplicitAndAvailableOnlyInDev(): void
    {
        $helper = $this->helper([$this->catalogue([$this->envelope()])]);
        $preparation = $this->createStub(SystemUpdatePreparationHelper::class);
        $preparation->method('cleanupAvailable')->willReturn(false);
        $wf = $this->workflow(true, 'dev', $helper, preparation: $preparation);
        self::assertTrue($wf->context()['simulation_allowed']);
        $result = $wf->check('1.0.0');
        self::assertTrue($result['simulation']);
        self::assertSame('1.1.0', $result['available_update']['version']);

        $this->expectException(ExceptionResponse::class);
        $this->workflow(true, '1.0.0', $this->helper([]))->check('1.0.1');
    }

    public function testStableReleaseUsesItsInstalledVersionWithoutSimulation(): void
    {
        $helper = $this->helper([$this->catalogue([$this->envelope()])]);
        $result = $this->workflow(true, '1.0.0', $helper)->check();
        self::assertFalse($result['simulation']);
        self::assertSame('1.0.0', $result['source_version']);
    }

    public function testDownloadPreservesTheEffectiveSourceInDevAndStableReleases(): void
    {
        foreach ([['dev', '1.0.0', true], ['1.0.0', null, false]] as [$installed, $source, $simulation]) {
            $package = $this->createMock(SystemUpdatePackageHelper::class);
            $package->expects(self::once())->method('download')
                ->with('1.0.0', PHP_VERSION, '1.1.0', str_repeat('a', 64), $simulation)
                ->willReturn(['version' => '1.1.0']);
            $result = $this->workflow(true, $installed, $this->helper([]), $package)
                ->download('1.1.0', str_repeat('a', 64), $source);
            self::assertSame($simulation, $result['simulation']);
            self::assertSame('1.0.0', $result['source_version']);
        }
    }

    public function testAStableReleaseCannotSimulateTheDownloadSource(): void
    {
        $this->expectException(ExceptionResponse::class);
        $this->workflow(true, '1.0.0', $this->helper([]))->download('1.1.0', str_repeat('a', 64), '1.0.1');
    }

    public function testPreparationUsesTheEffectiveSourceAndAStableReleaseCannotSimulateIt(): void
    {
        foreach ([['dev', '1.0.0', true], ['1.0.0', null, false]] as [$installed, $source, $preview]) {
            $preparation = $this->createMock(SystemUpdatePreparationHelper::class);
            $preparation->expects(self::once())->method('prepare')
                ->with('1.0.0', PHP_VERSION, '1.1.0', str_repeat('a', 64), $preview)
                ->willReturn(['url' => '/lien-fixture']);
            $result = $this->workflow(true, $installed, $this->helper([]), null, $preparation)
                ->prepare('1.1.0', str_repeat('a', 64), $source);
            self::assertSame('/lien-fixture', $result['url']);
        }
        $this->expectException(ExceptionResponse::class);
        $this->workflow(true, '1.0.0', $this->helper([]))->prepare('1.1.0', str_repeat('a', 64), '1.0.1');
    }

    public function testManifestDownloadFallsBackBeforePinningThePackage(): void
    {
        $helper = $this->helper([new ServerUnavailableException(), json_encode($this->envelope())]);
        $result = $helper->manifestForDownload('1.0.0', '8.5.0', '1.1.0', str_repeat('a', 64));
        self::assertSame([self::SECOND], $result['servers']);
        self::assertCount(1, $result['failures']);
        self::assertSame([self::FIRST . '?action=manifest&version=1.1.0', self::SECOND . '?action=manifest&version=1.1.0'], $helper->requests);
    }

    public function testResetRequiresRootAlsoInDevAndUsesAPostRouteWithoutClientPaths(): void
    {
        foreach (['dev', '1.0.0'] as $installed) {
            $preparation = $this->createMock(SystemUpdatePreparationHelper::class);
            $preparation->expects(self::once())->method('reset')->willReturn(['reset' => true]);
            self::assertSame(['reset' => true], $this->workflow(true, $installed, $this->helper([]), null, $preparation)->reset());
        }
        $method = new \ReflectionMethod(\YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminApiController::class, 'resetSystemUpdate');
        $route = $method->getAttributes()[0]->newInstance();
        self::assertSame([\YTassy\Tycms\Request\RequestMethod::POST], $route->methods);
        self::assertSame([], $method->getParameters());
    }

    public function testPackageRoutesUsePostAndDeclareAllParameters(): void
    {
        foreach (['downloadSystemUpdate', 'prepareSystemUpdate'] as $action) {
            $method = new \ReflectionMethod(\YTassy\Tycms\CoreExts\Core\Controllers\Admin\AdminApiController::class, $action);
            $attributes = array_map(static fn(\ReflectionAttribute $attribute) => $attribute->newInstance(), $method->getAttributes());
            self::assertSame([\YTassy\Tycms\Request\RequestMethod::POST], $attributes[0]->methods);
            self::assertSame(['version', 'sha256', 'sourceVersion'], array_map(static fn($attribute) => $attribute->name, array_slice($attributes, 1)));
        }
    }

    public function testDevCanTestTheLatestPublishedReleaseEvenWithAnIncompatibleSource(): void
    {
        $helper = $this->helper([$this->catalogue([
            $this->envelope('1.9.0', ['1.8.0']), $this->envelope('1.10.0', []), $this->envelope(),
        ])]);
        $result = $helper->check('1.0.0', '8.5.0', true);
        self::assertSame('1.10.0', $result['available_update']['version']);
        self::assertTrue($result['dev_preview']);
        self::assertTrue($result['php_compatible']);
        self::assertSame(['1.1.0'], $result['compatible_versions']);
    }

    public function testDevCanTestAnInitialReleaseEqualToTheSimulatedSource(): void
    {
        $helper = $this->helper([$this->catalogue([$this->envelope('1.0.0', [])])]);
        $result = $helper->check('1.0.0', '8.4.27', true);
        self::assertSame('1.0.0', $result['available_update']['version']);
        self::assertSame([], $result['compatible_versions']);
    }

    public function testDevStillRejectsAnUnknownSignature(): void
    {
        $envelope = $this->envelope();
        $envelope['key_id'] = 'unknown';
        $helper = $this->helper([$this->catalogue([$envelope])]);
        $this->expectException(RuntimeException::class);
        $helper->check('1.0.0', '8.5.0', true);
    }

    public function testAStableReleaseRejectsThePreviewAtTheHelperBoundary(): void
    {
        // Une autre constante de release nécessite un processus indépendant, sans amorcer le site.
        $code = 'define("APP_VERSION", "1.1.0"); define("APP_ROOT", $argv[1]); '
            . 'require $argv[1]."/releases/vdev/vendor/autoload.php"; '
            . 'require $argv[1]."/tests/Support/Configuration.php"; '
            . '$helper=new YTassy\\Tycms\\CoreExts\\Core\\Helper\\SystemUpdate\\SystemUpdateHelper(new YTassy\\Tycms\\Application(new Configuration())); '
            . 'try {$helper->check("1.0.0", PHP_VERSION, true); exit(1);} '
            . 'catch (RuntimeException $e) {echo $e->getMessage();}';
        $process = proc_open([PHP_BINARY, '-r', $code, APP_ROOT], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $errors);
        self::assertStringContainsString('réservé à la release dev', $output);
    }
}
