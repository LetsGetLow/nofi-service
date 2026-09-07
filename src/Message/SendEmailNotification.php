<?php

declare(strict_types=1);

namespace Nofi\Message;

use Nofi\Dto\SendNotificationDto;
use Override;
use Symfony\Component\Messenger\Attribute\AsMessage;

#[AsMessage("async")]
class SendEmailNotification implements NotificationMessage
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

    #[Override]
    public function getNotificationDto(): SendNotificationDto
    {
        return $this->notificationDto;
    }

    #[Override]
    public function getUserId(): string
    {
        return $this->userId;
    }
}
