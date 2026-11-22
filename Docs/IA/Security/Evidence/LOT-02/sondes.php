<?php

// Sondes initiales, avant correction : aucune configuration locale, session HTTP ou connexion SQL.
require dirname(__DIR__, 5) . '/src/releases/vdev/vendor/autoload.php';

use Symfony\Component\Filesystem\Path;
use YTassy\Tycms\Lib\HtmlSanitizer\HtmlSanitizer;
use YTassy\Tycms\CoreExts\Core\Helper\Mail\EmailTemplateContent;
use YTassy\Tycms\Lib\Scss\ScssCompiler;

$results = [];
$html = '<img src="/medias/factice.png" srcset="https://refuse.example/a.png 1x">';
$refresh = '<meta http-equiv="refresh" content="0 url=https://refuse.example/"><p>Texte</p>';
$results['wysiwyg_srcset_retire'] = !str_contains(HtmlSanitizer::sanitize($html), 'srcset');
$results['wysiwyg_meta_retire'] = !str_contains(HtmlSanitizer::sanitize($refresh), '<meta');
$results['wysiwyg_media_autorite_malformee_retiree'] = !str_contains(
    HtmlSanitizer::sanitize('<img src="///refuse.example/a.png">'), 'refuse.example',
);

$method = new ReflectionMethod(EmailTemplateContent::class, 'sanitizer');
$email = $method->invoke(null);
$results['email_srcset_retire'] = !str_contains($email->sanitize($html), 'srcset');
$results['email_meta_retire'] = !str_contains($email->sanitize($refresh), '<meta');

// Reproduire uniquement le calcul vulnérable du composant, sans accéder au fichier.
$results['filesystem_primitif_amont_reproduit'] = Path::isBasePath('/base', '/base/../x://../secret.txt');

$fixture = '/tmp/tyframework-security-lot02/scss-fixture';
if (!is_dir($fixture . '/safe')) mkdir($fixture . '/safe', 0700, true);
file_put_contents($fixture . '/safe/_ok.scss', '.valide { color: red; }');
file_put_contents($fixture . '/_private.scss', '.hors-racine { color: blue; }');
$results['scss_import_legitime'] = ScssCompiler::compileScss('@import "ok";', $fixture . '/safe') !== null;
$results['scss_sortie_racine_refusee'] = ScssCompiler::compileScss('@import "../private";', $fixture . '/safe') === null;

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit(in_array(false, $results, true) ? 1 : 0);
