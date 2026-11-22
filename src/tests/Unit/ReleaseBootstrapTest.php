<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReleaseBootstrapTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        // Copier uniquement le sélecteur dans une installation jetable, sans base.
        $this->root = sys_get_temp_dir() . '/ty-release-bootstrap-' . bin2hex(random_bytes(8));
        foreach (['public', 'var', 'releases/vdev'] as $directory) {
            mkdir($this->root . '/' . $directory, 0700, true);
        }

        copy(APP_ROOT . '/public/index.php', $this->root . '/public/index.php');
        file_put_contents($this->root . '/releases/vdev/index.php', '<?php echo "release-ok";');
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function testTheSelectedReleaseIsLoaded(): void
    {
        $this->writeState(['schema_version' => 1, 'active_release' => 'vdev']);
        [$status, $output] = $this->executeBootstrap();

        self::assertSame(0, $status);
        self::assertSame('release-ok', $output);
    }

    #[DataProvider('invalidStates')]
    public function testInvalidStatesFailWithoutExposingPaths(?string $contents): void
    {
        if ($contents !== null) {
            file_put_contents($this->root . '/var/releases-states.json', $contents);
        }

        $this->assertBootstrapRefusesTheState();
    }

    public static function invalidStates(): iterable
    {
        yield 'fichier absent' => [null];
        yield 'JSON tronqué' => ['{"schema_version":'];
        yield 'racine scalaire' => ['true'];
        yield 'schéma inconnu' => ['{"schema_version":2,"active_release":"vdev"}'];
        yield 'schéma mal typé' => ['{"schema_version":"1","active_release":"vdev"}'];
        yield 'release absente' => ['{"schema_version":1}'];
        yield 'release mal typée' => ['{"schema_version":1,"active_release":[]}'];
        yield 'traversée de dossiers' => ['{"schema_version":1,"active_release":"../../outside"}'];
        yield 'release inexistante' => ['{"schema_version":1,"active_release":"vabsent"}'];
    }

    public function testASymlinkCannotSelectCodeOutsideTheReleases(): void
    {
        // Un dossier au préfixe similaire ne doit pas passer le contrôle de confinement.
        rename($this->root . '/releases/vdev', $this->root . '/releases-other');
        symlink($this->root . '/releases-other', $this->root . '/releases/vdev');
        $this->writeState(['schema_version' => 1, 'active_release' => 'vdev']);

        $this->assertBootstrapRefusesTheState();
    }

    private function writeState(array $state): void
    {
        file_put_contents($this->root . '/var/releases-states.json', json_encode($state, JSON_THROW_ON_ERROR));
    }

    private function assertBootstrapRefusesTheState(): void
    {
        [$status, $output, $errors] = $this->executeBootstrap();

        self::assertSame(1, $status);
        self::assertSame('Le site est temporairement indisponible.', $output);
        self::assertStringNotContainsString($this->root, $output);
        self::assertStringContainsString('HTTP_STATUS=500', $errors);
    }

    private function executeBootstrap(): array
    {
        // Isoler les exit() et relever le statut HTTP sans serveur ni navigateur.
        $process = proc_open([
            PHP_BINARY, '-d', 'display_errors=0', '-r',
            'register_shutdown_function(static function () { fwrite(STDERR, "HTTP_STATUS=" . http_response_code()); }); require $argv[1];',
            $this->root . '/public/index.php',
        ], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($process), $output, $errors];
    }

    private function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) as $name) {
            if ($name === '.' || $name === '..') continue;

            $path = $directory . '/' . $name;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
