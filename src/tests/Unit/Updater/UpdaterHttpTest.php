<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class UpdaterHttpTest extends TestCase
{
    private string $root;
    private string $entry;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/tycms-updater-' . bin2hex(random_bytes(8));
        foreach (['', '/var', '/var/updates', '/public', '/updater'] as $dir) mkdir($this->root . $dir);
        foreach (['index.php', 'OperationStore.php', 'UpdaterService.php', 'ReleasePreparer.php', 'Maintenance.php', 'SourceConfiguration.php'] as $file) {
            copy(__DIR__ . '/../../../updater/' . $file, $this->root . '/updater/' . $file);
        }
        $this->entry = 'update_' . str_repeat('a', 64) . '.php';
        file_put_contents($this->root . '/public/' . $this->entry, "<?php require_once dirname(__DIR__) . '/updater/index.php';");
        file_put_contents($this->root . '/var/last_update_data.json', '{}');
        $this->writeState(true);
    }

    private function writeState(bool $active): void
    {
        $sha = str_repeat('b', 64);
        file_put_contents($this->root . '/var/releases-states.json', json_encode([
            'schema_version' => 1, 'active_release' => 'vdev', 'active_operation' => $active ? [
                'id' => str_repeat('c', 32), 'status' => 'prepared', 'entrypoint' => $this->entry,
                'source_version' => '1.0.0', 'version' => '1.1.0', 'development' => true,
                'sha256' => $sha, 'package_id' => 'v1_1_0-' . $sha,
            ] : null,
        ]));
    }

    /** Lance une requête isolée sans toucher à une installation réelle. */
    private function request(string $method, array $cookies = [], array $headers = [], array $post = []): string
    {
        $server = array_merge(['REQUEST_METHOD' => $method, 'SCRIPT_FILENAME' => $this->root . '/public/' . $this->entry,
            'HTTP_HOST' => 'example.test', 'HTTPS' => 'on'], $headers);
        $script = '$_SERVER = ' . var_export($server, true) . '; $_COOKIE = ' . var_export($cookies, true)
            . '; $_POST = ' . var_export($post, true) . '; require ' . var_export($this->root . '/public/' . $this->entry, true) . ';';
        $process = proc_open([PHP_BINARY, '-r', $script], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $error);
        self::assertTrue($error === '' || $error === "Tycms updater : échec RuntimeException\n", $error);
        return $output;
    }

    public function testAccessRevocationAndPostProtection(): void
    {
        self::assertStringContainsString('/assets_updater/app/main.js', $this->request('GET'));
        $token = str_repeat('d', 64);
        $cookie = ['tycms_updater_csrf' => $token];
        $headers = ['HTTP_X_CSRF_TOKEN' => $token, 'HTTP_ORIGIN' => 'https://example.test'];
        $status = json_decode($this->request('POST', $cookie, $headers, ['action' => 'status']), true, 32, JSON_THROW_ON_ERROR);
        self::assertSame('1.1.0', $status['version']);
        self::assertSame('new', $status['phase']);
        self::assertStringContainsString('non autorisée', $this->request('POST', $cookie, [], ['action' => 'status']));
        self::assertStringContainsString('non autorisée', $this->request('POST', $cookie, [], ['action' => 'prepare']));
        self::assertStringContainsString('non autorisée', $this->request('POST', $cookie, array_replace($headers, ['HTTP_ORIGIN' => 'https://evil.test']), ['action' => 'status']));
        self::assertStringContainsString('inconnue', $this->request('POST', $cookie, $headers, ['action' => 'deploy']));
        self::assertStringContainsString('ne peuvent pas', $this->request('POST', $cookie, $headers, ['action' => 'migrate']));
        $this->writeState(false);
        self::assertSame('Opération indisponible.', $this->request('GET'));
        self::assertSame('Opération indisponible.', $this->request('POST', $cookie, $headers, ['action' => 'status']));
    }

    protected function tearDown(): void
    {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->root);
    }
}
