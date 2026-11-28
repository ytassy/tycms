<?php

declare(strict_types=1);

namespace YTassy\Tycms\CoreExts\Core\ExtensionApi\Notification;

use YTassy\Tycms\CoreExts\Core\Notification\NotificationChannelRegistry;

/**
 * Fournisseur de types de canaux de notification.
 *
 * L'enregistrement a lieu pendant `onPrepareExtensions`, avec les autres
 * registres de capacités. Le transport et le handler de livraison restent
 * fournis par Core.
 */
interface INotificationChannelProvider
{
    public function coreOnRegisterNotificationChannels(NotificationChannelRegistry $registry): void;
}
