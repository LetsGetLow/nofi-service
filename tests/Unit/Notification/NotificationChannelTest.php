<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Notification;

use Nofi\Dto\SendNotificationDto;
use Nofi\Message\SendEmailNotification;
use Nofi\Message\SendPushNotification;
use Nofi\Notification\EmailNotificationPayload;
use Nofi\Notification\NotificationChannel;
use Nofi\Notification\PushNotificationPayload;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Everything that differs between channels is reached through the enum, so
 * this is where a new channel is proved wired up. It replaces
 * NotificationMessageFactoryTest, which covered one of the four places this
 * behaviour used to be spread across.
 */
#[TestDox("NotificationChannel behavior")]
final class NotificationChannelTest extends TestCase
{
    #[Test]
    public function everyChannelBuildsItsOwnPayload(): void
    {
        self::assertInstanceOf(
            EmailNotificationPayload::class,
            NotificationChannel::EMAIL->payloadFrom($this->emailDto()),
        );
        self::assertInstanceOf(
            PushNotificationPayload::class,
            NotificationChannel::PUSH->payloadFrom($this->pushDto()),
        );
    }

    #[Test]
    public function everyChannelBuildsItsOwnMessage(): void
    {
        $id = Uuid::v7()->toRfc4122();
        $dto = $this->emailDto();

        $message = NotificationChannel::EMAIL->newMessage($id, $dto, "user-1");

        self::assertInstanceOf(SendEmailNotification::class, $message);
        self::assertSame($id, $message->getNotificationId());
        self::assertSame("user-1", $message->getUserId());
        self::assertSame($dto, $message->getNotificationDto());

        self::assertInstanceOf(
            SendPushNotification::class,
            NotificationChannel::PUSH->newMessage($id, $this->pushDto(), "user-1"),
        );
    }

    /**
     * The payload a channel builds is the one its delivery expects. Nothing in
     * the type system enforces that pairing — NotificationDelivery::deliver()
     * has to accept the interface — so it is asserted here instead.
     */
    #[Test]
    public function thePayloadAndTheMessageAgreeOnTheChannel(): void
    {
        foreach (NotificationChannel::cases() as $channel) {
            $dto = $channel === NotificationChannel::EMAIL ? $this->emailDto() : $this->pushDto();

            self::assertSame(
                $channel,
                $channel->newMessage("id", $dto, "user-1")->getNotificationDto()->channel,
                sprintf("%s must carry its own channel", $channel->value),
            );
        }
    }

    private function emailDto(): SendNotificationDto
    {
        $dto = new SendNotificationDto();
        $dto->channel = NotificationChannel::EMAIL;
        $dto->sender = "noreply@example.com";
        $dto->subject = "Hello";
        $dto->message = "Hi there";
        $dto->recipients = ["alice@example.com"];

        return $dto;
    }

    private function pushDto(): SendNotificationDto
    {
        $dto = new SendNotificationDto();
        $dto->channel = NotificationChannel::PUSH;
        $dto->title = "Order shipped";
        $dto->message = "On its way";
        $dto->tokens = ["device-token-1"];

        return $dto;
    }
}
