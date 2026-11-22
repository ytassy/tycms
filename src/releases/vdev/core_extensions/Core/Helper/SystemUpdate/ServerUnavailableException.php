<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper\SystemUpdate;

/** Seules les pannes de transport ou erreurs HTTP autorisent le serveur suivant. */
final class ServerUnavailableException extends \RuntimeException
{
}
