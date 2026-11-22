<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\Helper;

use YTassy\Tycms\CoreExts\Core\ExtensionApi\BaseHelper;

final class LogReaderHelper extends BaseHelper
{
    public function status(): array
    {
        return $this->logger->status();
    }

    public function read(array $filters, ?string $cursor): array
    {
        return $this->logger->reader()->page($filters, $cursor);
    }
}
