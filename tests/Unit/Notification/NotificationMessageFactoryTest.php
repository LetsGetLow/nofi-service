<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Notification;

use Nofi\Dto\SendNotificationDto;
use Nofi\Message\SendEmailNotification;
use Nofi\Notification\NotificationChannel;
use Nofi\Notification\NotificationMessageFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[TestDox("NotificationMessageFactory behavior")]
final class NotificationMessageFactoryTest extends TestCase
{
    #[Test]
    public function createBuildsAnEmailMessage(): void
    {
        $dto = new SendNotificationDto();
        $dto->channel = NotificationChannel::EMAIL;
        $dto->sender = "noreply@example.com";
        $dto->subject = "Hello";
        $dto->message = "Hi there";
        $dto->recipients = ["alice@example.com"];

        $factory = new NotificationMessageFactory();
        $notificationId = Uuid::v7();

        $message = $factory->create($notificationId, $dto, "user-1");

        self::assertInstanceOf(SendEmailNotification::class, $message);
        self::assertSame($notificationId->toRfc4122(), $message->getNotificationId());
        self::assertSame("user-1", $message->getUserId());
        self::assertSame($dto, $message->getNotificationDto());
    }
}
