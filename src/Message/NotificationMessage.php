<?php

declare(strict_types=1);

namespace Nofi\Message;

interface NotificationMessage
{
    public function getNotificationId(): string;
}
