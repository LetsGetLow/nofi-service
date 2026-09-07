<?php

declare(strict_types=1);

namespace Nofi\Message;

use Nofi\Dto\SendNotificationDto;
use Override;
use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage("async")]
class SendPushNotification implements NotificationMessage
{
    public function __construct(
        private string              $notificationId,
        private SendNotificationDto $notificationDto,
        private string              $userId,
    ) {}

    #[Override]
    public function getNotificationId(): string
    {
        return $this->notificationId;
    }

    public function getNotificationDto(): SendNotificationDto
    {
        return $this->notificationDto;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }
}
