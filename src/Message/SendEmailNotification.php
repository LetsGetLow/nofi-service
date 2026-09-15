<?php

declare(strict_types=1);

namespace Nofi\Message;

use Nofi\Notification\Email\EmailNotificationPayload;
use Override;
use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage("async")]
final readonly class SendEmailNotification implements NotificationMessage
{
    public function __construct(
        private string $notificationId,
        private EmailNotificationPayload $payload,
    ) {
    }

    #[Override]
    public function getNotificationId(): string
    {
        return $this->notificationId;
    }

    #[Override]
    public function getPayload(): EmailNotificationPayload
    {
        return $this->payload;
    }
}
