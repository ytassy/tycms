<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\Extension;

/**
 * Identité publique du catalogue officiel.
 *
 * La clé publique est la racine de confiance proposée par défaut. Elle reste
 * adoptée explicitement par ROOT ; le serveur ne peut jamais l'imposer.
 */
final class OfficialCatalog
{
    public const NAME = 'Catalogue officiel';
    public const URL = 'https://www.tycms.fr/tycms-catalog/official';
    public const KEY_ID = 'extensions-store-official';
    public const PUBLIC_KEY = 'XC2P8JCFsOcLLNV4eVpiLndPNqrtjV0Zt3b0II2Z1pg=';
}
