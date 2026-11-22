<?php

namespace YTassy\Tycms\Tests\Unit\Plugins\Core;

use ReflectionMethod;
use RuntimeException;
use YTassy\Tycms\CoreExts\Core\Helper\Crypt\CryptHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Video\SecureVideoHelper;
use YTassy\Tycms\CoreExts\Core\Helper\Video\SecureVideoKey;
use YTassy\Tycms\Tests\Support\FrameworkTestCase;

final class SecureVideoTest extends FrameworkTestCase
{
    public function testHistoricalKeyFormatRoundTripAndAuthentication(): void
    {
        $key = random_bytes(16);
        $crypt = SecureVideoKey::encrypt($key, 'un-mot-de-passe');

        self::assertSame(65, strlen($crypt));
        self::assertSame('VKEY1', substr($crypt, 0, 5));
        self::assertSame($key, SecureVideoKey::decrypt($crypt, 'un-mot-de-passe'));

        $transport = SecureVideoKey::encodeForPlayer($key);
        self::assertSame(30, strlen($transport));
        self::assertMatchesRegularExpression('/\A==B64.[A-Za-z0-9+\/]+={0,2}\z/D', $transport);
        self::assertSame($key, base64_decode(substr($transport, 6), true));

        foreach (['autre-mot-de-passe', 'un-mot-de-passe'] as $index => $password) {
            $damaged = $crypt;
            if ($index === 1) $damaged[64] = chr(ord($damaged[64]) ^ 1);
            try {
                SecureVideoKey::decrypt($damaged, $password);
                self::fail('Une clé invalide a été acceptée.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testSourcesStayInsideAnAllowedMediaOrLibraryFolder(): void
    {
        SecureVideoHelper::validateSource('/medias/space-1/abc/playlist.m3u8');
        SecureVideoHelper::validateSource('bib1:cours/video/playlist.m3u8');
        SecureVideoHelper::validateSource('bib1:cours/video/le-cours.m3u8');

        foreach ([
            '/medias/space-1/%2e%2e/playlist.m3u8',
            '/medias/space-1/dir%2fsecret/playlist.m3u8',
            '/medias/space-1/playlist.m3u8',
            'bib1:../secret/playlist.m3u8',
            'bib1:https://remote.test/playlist.m3u8',
            'bib1:cours/master.m3u8.php',
            'file:///etc/passwd',
        ] as $source) {
            try {
                SecureVideoHelper::validateSource($source);
                self::fail('Une source HLS invalide a été acceptée : ' . $source);
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testPlaylistRewritesOnlyTheHistoricalFiles(): void
    {
        $helper = new SecureVideoHelper($this->createApplication());
        // La structure et l'IV reprennent l'exemple kenyoku, sans dépendre de ses fichiers privés.
        $playlist = "#EXTM3U\n#EXT-X-VERSION:3\n#EXT-X-TARGETDURATION:20\n#EXT-X-MEDIA-SEQUENCE:0\n#EXT-X-KEY:METHOD=AES-128,URI=\"[URL_OF_KEY]\",IV=0x00000000000000000000000000000000\n#EXTINF:20.000000,\nsegment_000.ts\n#EXT-X-ENDLIST\n";
        $result = (new ReflectionMethod(SecureVideoHelper::class, 'playlist'))
            ->invoke($helper, $playlist, 7, '/medias/space-1/abc/playlist.m3u8');

        self::assertStringContainsString('source=%2Fmedias%2Fspace-1%2Fabc%2Fplaylist.m3u8&file=video.key', $result);
        self::assertStringContainsString('&file=segment_000.ts', $result);
        self::assertStringNotContainsString('[URL_OF_KEY]', $result);

        foreach (['https://evil.test/segment.ts', '#EXT-X-MAP:URI="/etc/passwd"', '../segment_000.ts'] as $injected) {
            try {
                (new ReflectionMethod(SecureVideoHelper::class, 'playlist'))->invoke($helper, "#EXTM3U\n" . $injected, 7, 'bib1:abc/playlist.m3u8');
                self::fail('Une référence externe a été acceptée.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testLibraryMapRequiresReadableAbsoluteFolders(): void
    {
        self::assertSame(['bib1' => sys_get_temp_dir()], SecureVideoHelper::parseLibraries('{"bib1":"' . sys_get_temp_dir() . '"}'));
        foreach (['[]', '{"bib1":"../relative"}', '{"Bib 1":"/tmp"}', '{"bib1":"/missing-hls-library"}'] as $input) {
            try {
                SecureVideoHelper::parseLibraries($input);
                self::fail('Une bibliothèque invalide a été acceptée.');
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testLibraryMapSurvivesConfigurationReloadAndLegacyFormats(): void
    {
        $helper = new SecureVideoHelper($this->createApplication());
        $libraries = ['bib1' => sys_get_temp_dir()];
        $input = json_encode((object) $libraries, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        // Le formulaire livre une chaîne que la colonne JSON doit conserver telle quelle.
        $prepared = $helper->prepareConfiguration(7, [
            'video_hls_libraries' => $input,
            'video_hls_password' => 'secret-video',
        ]);
        self::assertSame($input, $prepared['video_hls_libraries']);
        self::assertSame('secret-video', $prepared['video_hls_password']);
        self::assertArrayNotHasKey('video_hls_password_encrypted', $prepared);

        // L'ORM décode config.params en objet lors du rechargement.
        $stored = json_decode(json_encode((object) $prepared, JSON_THROW_ON_ERROR), false, 32, JSON_THROW_ON_ERROR);
        $editor = $helper->configurationForEditor(7, (array) $stored);
        self::assertSame($libraries, json_decode($editor['video_hls_libraries'], true, 32, JSON_THROW_ON_ERROR));
        self::assertSame('secret-video', $editor['video_hls_password']);

        // Les valeurs enregistrées avant la correction étaient des tableaux,
        // devenus des objets après le même décodage ORM.
        foreach ([(object) $libraries, $libraries] as $legacy) {
            $editor = $helper->configurationForEditor(7, ['video_hls_libraries' => $legacy]);
            self::assertSame($libraries, json_decode($editor['video_hls_libraries'], true, 32, JSON_THROW_ON_ERROR));
        }
    }

    public function testPlainKeyIsEncryptedOnFirstReadAndNoPlainCopyRemains(): void
    {
        $app = $this->createApplication();
        $helper = new SecureVideoHelper($app);
        $folder = sys_get_temp_dir() . '/secure-video-test-' . bin2hex(random_bytes(8));
        mkdir($folder, 0700);
        $key = random_bytes(16);
        file_put_contents($folder . '/video.key', $key);
        $params = (object) ['video_hls_password_encrypted' =>
            (new CryptHelper($app))->encrypt('secret-video', 'video:hls:7')];

        try {
            $read = new ReflectionMethod(SecureVideoHelper::class, 'readKey');
            self::assertSame($key, $read->invoke($helper, $folder, $params, 7));
            self::assertFileDoesNotExist($folder . '/video.key');
            self::assertFileExists($folder . '/video.key.crypt');
            self::assertSame($key, $read->invoke($helper, $folder, $params, 7));
        } finally {
            @unlink($folder . '/video.key');
            @unlink($folder . '/video.key.crypt');
            @rmdir($folder);
        }
    }
}
