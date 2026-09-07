<?php

declare(strict_types=1);

namespace Nofi\Message;

use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage("async")]
final class DeleteNotification
{
    public function __construct(private readonly string $notificationId) {}

    public function getNotificationId(): string
    {
        return $this->notificationId;
    }
}
