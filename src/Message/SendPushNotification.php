<?php

declare(strict_types=1);

namespace Nofi\Message;

use Nofi\Notification\Push\PushNotificationPayload;
use Override;
use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage("async")]
final readonly class SendPushNotification implements NotificationMessage
{
    public function __construct(
        private string $notificationId,
        private PushNotificationPayload $payload,
    ) {
    }

    #[Override]
    public function getNotificationId(): string
    {
        return $this->notificationId;
    }

    #[Override]
    public function getPayload(): PushNotificationPayload
    {
        return $this->payload;
    }
}
