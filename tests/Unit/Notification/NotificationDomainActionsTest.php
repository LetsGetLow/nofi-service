<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Notification;

use DateTimeImmutable;
use Nofi\Entity\Notification;
use Nofi\Entity\NotificationRecipient;
use Nofi\Entity\User;
use Nofi\Notification\NotificationChannel;
use Nofi\Notification\NotificationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox("Notification domain actions")]
final class NotificationDomainActionsTest extends TestCase
{
    #[Test]
    public function notificationActionsUpdateStateThroughIntentMethods(): void
    {
        $notification = new Notification('notif-1');
        $user = new User();
        $createdAt = new DateTimeImmutable('2026-08-10 12:00:00');
        $scheduledAt = new DateTimeImmutable('2026-08-10 13:00:00');

        $notification
            ->assignPayload(['sender' => 'noreply@example.com'])
            ->assignChannel(NotificationChannel::EMAIL)
            ->assignCreatedBy($user)
            ->recordCreatedAt($createdAt)
            ->schedule($scheduledAt)
            ->markQueued()
            ->markProcessing()
            ->markSent();

        self::assertSame(NotificationStatus::SENT, $notification->getStatus());
        self::assertSame(NotificationChannel::EMAIL, $notification->getChannel());
        self::assertSame($user, $notification->getCreatedBy());
        self::assertSame($createdAt, $notification->getCreatedAt());
        self::assertSame($scheduledAt, $notification->getScheduledAt());
        self::assertSame(['sender' => 'noreply@example.com'], $notification->getPayload());
    }

    #[Test]
    public function recipientActionsUpdateStateThroughIntentMethods(): void
    {
        $notification = new Notification('notif-2');
        $recipient = new NotificationRecipient();

        $recipient
            ->attachToNotification($notification)
            ->assignRecipient('alice@example.com')
            ->markProcessing()
            ->markSentAt(new DateTimeImmutable('2026-08-10 14:00:00'))
            ->markSent();

        self::assertSame($notification, $recipient->getNotification());
        self::assertSame('alice@example.com', $recipient->getRecipient());
        self::assertSame(NotificationStatus::SENT, $recipient->getStatus());
        self::assertEquals(new DateTimeImmutable('2026-08-10 14:00:00'), $recipient->getSentAt());
    }
}
