<?php

declare(strict_types=1);

namespace Nofi\Service\Push;

use Nofi\Entity\Notification;

interface PushService
{
    /**
     * Send push notifications to all recipients of a notification.
     * 
     * @param Notification $notification
     * @param array<int, string> $deviceTokens
     * @param string $title
     * @param string $message
     * @param string|null $icon
     * @param array<string, mixed> $data
     * @return array<int, array{token: string, success: bool, error: string|null}>
     */
    public function send(
        Notification $notification,
        array $deviceTokens,
        string $title,
        string $message,
        ?string $icon = null,
        array $data = [],
    ): array;
}
