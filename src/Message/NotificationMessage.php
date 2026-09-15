<?php

declare(strict_types=1);

namespace Nofi\Message;

use Nofi\Notification\NotificationPayload;

interface NotificationMessage
{
    public function getNotificationId(): string;

    public function getPayload(): NotificationPayload;
}
