<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Automation\Notification;

final class NotificationException extends \RuntimeException
{
    public function __construct(
        public readonly int $httpStatus = 0,
        public readonly int $curlErrno = 0,
    ) {
        parent::__construct('Notification échouée.');
    }
}
