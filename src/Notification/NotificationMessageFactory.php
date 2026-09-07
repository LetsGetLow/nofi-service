<?php

declare(strict_types=1);

namespace Nofi\Notification;

use Nofi\Dto\SendNotificationDto;
use Nofi\Message\NotificationMessage;
use Nofi\Message\SendEmailNotification;
use Nofi\Message\SendPushNotification;
use Symfony\Component\Uid\Uuid;

final class NotificationMessageFactory
{
    public function create(Uuid $notificationId, SendNotificationDto $dto, string $userId): NotificationMessage
    {
        return match($dto->channel) {
            NotificationChannel::EMAIL => new SendEmailNotification(
                $notificationId->toRfc4122(),
                $dto,
                $userId,
            ),
            NotificationChannel::PUSH => new SendPushNotification(
                $notificationId->toRfc4122(),
                $dto,
                $userId,
            ),
        };
    }
}
