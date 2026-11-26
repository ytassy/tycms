<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\JobQueue;

use RuntimeException;

/**
 * Le principal d'exécution d'un job n'est plus utilisable.
 *
 * Traité sans nouvelle tentative : rejouer ne réparerait rien tant que le
 * compte est absent ou bloqué.
 */
final class JobPrincipalException extends RuntimeException
{
}
