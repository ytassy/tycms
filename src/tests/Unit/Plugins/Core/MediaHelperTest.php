<?php

declare(strict_types=1);

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use Configuration;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use YTassy\Tycms\Application;
use YTassy\Tycms\CoreExts\Core\Helper\Media\MediaHelper;
use YTassy\Tycms\CoreExts\Core\Models\Space;

final class MediaHelperTest extends TestCase
{
    private string $directory;
    private MediaHelper $helper;
    private Space $space;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ty-media-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory));
        $this->space = new class($this->directory) extends Space {
            public function __construct(private readonly string $directory) {}
            public function getMediasDir(): string { return $this->directory; }
        };
        $this->space->medias_dir = 'test';
        $this->helper = new MediaHelper(new Application(new Configuration()));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            if (is_file($path)) unlink($path);
        }
        rmdir($this->directory);
    }

    public function testVideoSelectionChecksTheActualMimeAndSpace(): void
    {
        // En-tête MP4 minimal : le test vérifie le MIME, sans lecteur ni codec externe.
        file_put_contents($this->directory . '/video.mp4', "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom");
        $this->helper->assertSelectableFile($this->space, '/medias/test/video.mp4', 'video');
        self::assertSame('video/mp4', $this->helper->listDirectory($this->space)['entries'][0]['mime']);

        $this->expectException(RuntimeException::class);
        $this->helper->assertSelectableFile($this->space, '/medias/other/video.mp4', 'video');
    }

    public function testAVideoExtensionCannotDisguiseATextFile(): void
    {
        file_put_contents($this->directory . '/fake.mp4', 'Ceci est un texte, pas une vidéo.');
        $this->expectException(RuntimeException::class);
        $this->helper->assertSelectableFile($this->space, '/medias/test/fake.mp4', 'video');
    }

    public function testAudioSelectionChecksTheMimeSpaceAndCanonicalPath(): void
    {
        // WAV PCM minimal : aucun codec ni outil externe n’est nécessaire au contrôle MIME.
        $samples = str_repeat("\x80", 800);
        $wav = 'RIFF' . pack('V', 36 + strlen($samples)) . 'WAVEfmt ' . pack('VvvVVvv', 16, 1, 1, 8000, 8000, 1, 8)
            . 'data' . pack('V', strlen($samples)) . $samples;
        file_put_contents($this->directory . '/audio.wav', $wav);
        file_put_contents($this->directory . '/fake.mp3', 'Ceci est un texte, pas un fichier audio.');
        $this->helper->assertSelectableFile($this->space, '/medias/test/audio.wav', 'audio');

        foreach (['/medias/other/audio.wav', '/medias/test/fake.mp3', '/medias/test/absent.wav',
            '/medias/test/%2e%2e/audio.wav', '/medias/test/audio.wav?x=1', '/medias/test/audio%2Ewav'] as $url) {
            try {
                $this->helper->assertSelectableFile($this->space, $url, 'audio');
                self::fail('Ce fichier audio doit être refusé : ' . $url);
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }

        // Un lien symbolique ne permet pas de choisir un fichier hors de la racine du Space.
        $outside = tempnam(sys_get_temp_dir(), 'ty-audio-outside-');
        try {
            file_put_contents($outside, $wav);
            symlink($outside, $this->directory . '/outside.wav');
            $this->expectException(RuntimeException::class);
            $this->helper->assertSelectableFile($this->space, '/medias/test/outside.wav', 'audio');
        } finally {
            unlink($this->directory . '/outside.wav');
            unlink($outside);
        }
    }

    public function testCreateReadSaveAndRejectStaleVersion(): void
    {
        $version = $this->helper->createEditableFile($this->space, '', 'note.txt', '');
        self::assertSame(hash('sha256', ''), $version);
        self::assertSame(['content' => '', 'version' => $version], $this->helper->readEditableFile($this->space, 'note.txt'));

        $newVersion = $this->helper->saveEditableFile($this->space, 'note.txt', "Été\n", $version);
        self::assertSame(hash('sha256', "Été\n"), $newVersion);
        self::assertSame("Été\n", file_get_contents($this->directory . '/note.txt'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('changé depuis son ouverture');
        $this->helper->saveEditableFile($this->space, 'note.txt', 'ancienne version', $version);
    }

    public function testRejectsUneditableExtensionAndInvalidText(): void
    {
        self::assertContains('scss', $this->helper->getEditableExtensions());
        try {
            $this->helper->createEditableFile($this->space, '', 'script.php', '<?php');
            self::fail('Une extension non éditable doit être refusée.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('extension', $exception->getMessage());
        }

        $this->expectException(RuntimeException::class);
        $this->helper->createEditableFile($this->space, '', 'style.scss', "\xFF");
    }

    public function testRejectsPathTraversal(): void
    {
        $this->expectException(RuntimeException::class);
        $this->helper->readEditableFile($this->space, '../outside.txt');
    }

    public function testMediaSelectionChecksSpaceFileAndImageType(): void
    {
        file_put_contents($this->directory . '/logo.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/lR8AAAAASUVORK5CYII='
        ));
        file_put_contents($this->directory . '/notes.txt', 'texte');

        $this->helper->assertSelectableFile($this->space, '/medias/test/logo.png', 'image');
        $this->helper->assertSelectableFile($this->space, '/medias/test/notes.txt', 'all');
        $this->helper->assertSelectableFile($this->space, '', 'image');

        foreach (['/medias/test/notes.txt', '/medias/other/logo.png', '/medias/test/absent.png', '/medias/test/%2E%2E/logo.png'] as $url) {
            try {
                $this->helper->assertSelectableFile($this->space, $url, 'image');
                self::fail('Ce média doit être refusé : ' . $url);
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testDirectFileUrlAcceptsLocalAndHttpSourcesButRejectsUnsafeValues(): void
    {
        $this->helper->assertDirectFileUrl('/assets_core_vdev/tpl_main/images/my_logo.webp');
        $this->helper->assertDirectFileUrl('/medias/test/logo.png');
        $this->helper->assertDirectFileUrl('https://example.test/logo.png');

        foreach (['javascript:alert(1)', '//example.test/logo.png', '/assets/../secret', '/assets/%2e%2e/secret', '/image" onclick="alert(1)'] as $url) {
            try {
                $this->helper->assertDirectFileUrl($url);
                self::fail('Cette URL doit être refusée : ' . $url);
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }
}
