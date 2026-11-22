<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\WF;

use InvalidArgumentException;
use JsonException;
use YTassy\Tycms\CoreExts\Core\Helper\TraitAllHelpers;
use YTassy\Tycms\Exceptions\AccessDeniedException;
use YTassy\Tycms\Logging\LogReader;
use YTassy\Tycms\Response\ExceptionResponse;
use YTassy\Tycms\WF\BaseWF;

final class WFLogs extends BaseWF
{
    use TraitAllHelpers;

    public function status(): array
    {
        $this->assertRoot();
        return $this->coreLogReaderHelper->status();
    }

    public function read(string $filters, ?string $cursor = null): array
    {
        // Contrôler ROOT sur l'espace 1 avant tout accès aux métadonnées du disque.
        $this->assertRoot();
        if (strlen($filters) > 4096 || ($cursor !== null && strlen($cursor) > 500_000)) {
            ExceptionResponse::throwWarning('Filtres ou curseur de journal trop volumineux.');
        }
        try {
            $input = json_decode($filters, true, 8, JSON_THROW_ON_ERROR);
            if (!is_array($input)) throw new InvalidArgumentException('Filtres de journal invalides.');
            return $this->coreLogReaderHelper->read(LogReader::filters($input), $cursor);
        } catch (JsonException) {
            ExceptionResponse::throwWarning('Filtres ou curseur de journal invalides.');
        } catch (InvalidArgumentException $error) {
            ExceptionResponse::throwWarning($error->getMessage());
        } catch (\Throwable) {
            // Une panne du lecteur ne doit ni divulguer un chemin ni alimenter ses propres logs.
            return ['entries' => [], 'cursor' => null, 'partial' => false, 'corrupt_lines' => 0,
                'storage_error' => 'Historique indisponible, modifié ou expiré. Actualisez les journaux.'];
        }
    }

    private function assertRoot(): void
    {
        if (!$this->coreAclHelper->isRoot()) throw new AccessDeniedException();
    }
}
