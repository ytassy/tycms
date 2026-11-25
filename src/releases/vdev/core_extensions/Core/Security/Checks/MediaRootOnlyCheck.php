<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Security\Checks;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\ISecurityCheck;
use YTassy\Tycms\CoreExts\Core\ExtensionApi\Security\SecurityFinding;
use YTassy\Tycms\CoreExts\Core\Helper\Media\MediaHelper;

/**
 * Formats de médias interprétés plus tard par le site.
 *
 * Un SCSS peut être importé puis compilé par une template ou un outil de build,
 * un JS chargé par un outil. Ces formats changent le comportement du site après
 * un simple téléversement : s'ils restent ouverts à un porteur de `MEDIA_CRUD`,
 * l'ACL de code est contournée. Le contrôle compare donc la liste réservée par
 * la configuration à ce qu'elle laisse réellement faire.
 */
final class MediaRootOnlyCheck extends BaseHelper implements ISecurityCheck
{
    /** Formats dont l'exécution différée est connue, quel que soit le réglage. */
    private const SENSITIVE_EXTENSIONS = ['js', 'scss'];

    public function group(): string
    {
        return 'Médias';
    }

    public function check(): array
    {
        /** @var MediaHelper $media */
        $media = $this->getSharedExtensionsClass(MediaHelper::class);

        $rootOnly = $media->getRootOnlyExtensions();
        $allowed = $media->getAllowedExtensions();

        // Formats sensibles réellement autorisés sur ce site.
        $sensitive = array_values(array_intersect(self::SENSITIVE_EXTENSIONS, $allowed));

        if ($sensitive === []) {
            return [new SecurityFinding(
                'Formats exécutables',
                SecurityFinding::OK,
                'Aucun format interprété plus tard (JS, SCSS) n’est autorisé dans les médias.',
            )];
        }

        $unprotected = array_values(array_diff($sensitive, $rootOnly));

        if ($unprotected === []) {
            return [new SecurityFinding(
                'Formats réservés',
                SecurityFinding::OK,
                'Les formats interprétés plus tard (' . self::list($sensitive) . ') sont réservés aux comptes ROOT '
                . 'pour le téléversement, le renommage et l’édition.',
            )];
        }

        return [new SecurityFinding(
            'Formats non réservés',
            SecurityFinding::DANGER,
            'Les formats ' . self::list($unprotected) . ' sont modifiables par tout compte disposant de la '
            . 'permission de modification des médias. Un fichier déposé peut ensuite être compilé ou chargé '
            . 'par le site, ce qui revient à modifier son comportement sans passer par l’ACL de code.',
            'Ajoutez ces formats à `media_root_only_extensions`.',
            'configuration.php — media_root_only_extensions',
        )];
    }

    /** @param list<string> $extensions */
    private static function list(array $extensions): string
    {
        return implode(', ', array_map(static fn (string $extension): string => strtoupper($extension), $extensions));
    }
}
