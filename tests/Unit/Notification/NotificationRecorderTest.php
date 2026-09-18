<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Notification;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nofi\Entity\User;
use Nofi\Notification\Email\EmailAttachment;
use Nofi\Notification\Email\EmailNotificationPayload;
use Nofi\Notification\NotificationChannel;
use Nofi\Notification\NotificationRecorder;
use Nofi\Notification\NotificationRequest;
use Nofi\Notification\NotificationStatus;
use Nofi\Notification\Push\PushNotificationPayload;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox("NotificationRecorder behavior")]
final class NotificationRecorderTest extends TestCase
{
    #[Test]
    public function recordsEmailNotificationWithEmailPayload(): void
    {
        $payload = new EmailNotificationPayload('noreply@example.com', 'Hello', 'Hi there', null);
        $request = new NotificationRequest($payload, ['alice@example.com'], new DateTimeImmutable('+1 hour'));

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $user = new User()->setUsername('owner');

        $entityManager->expects(self::once())
            ->method('getReference')
            ->with(User::class, 'user-1')
            ->willReturn($user);

        $entityManager->expects(self::once())
            ->method('persist');

        $entityManager->expects(self::once())
            ->method('flush');

        $recorder = new NotificationRecorder($entityManager);
        $notification = $recorder->record('notif-1', 'user-1', $request);

        self::assertSame($request->scheduledAt, $notification->getScheduledAt());
        self::assertSame($user, $notification->getCreatedBy());
        self::assertSame(['alice@example.com'], array_map(
            static fn ($recipient) => $recipient->getRecipient(),
            $notification->getRecipients()->toArray(),
        ));
        self::assertSame('notif-1', $notification->getId());
        self::assertSame(NotificationChannel::EMAIL, $notification->getChannel());
        self::assertSame(NotificationStatus::QUEUED, $notification->getStatus());
        self::assertSame('noreply@example.com', $notification->getPayload()['sender']);
        self::assertSame('Hello', $notification->getPayload()['subject']);
    }

    #[Test]
    public function recordsPushNotificationWithPushPayload(): void
    {
        $payload = new PushNotificationPayload('Welcome', 'Hi there', data: ['url' => 'https://example.com']);
        $request = new NotificationRequest($payload, ['device-token-1', '/topics/news']);

        $entityManager = $this->createMock(EntityManagerInterface::class);
        $user = new User()->setUsername('owner');

        $entityManager->expects(self::once())
            ->method('getReference')
            ->with(User::class, 'user-1')
            ->willReturn($user);

        $entityManager->expects(self::once())
            ->method('persist');

        $entityManager->expects(self::once())
            ->method('flush');

        $recorder = new NotificationRecorder($entityManager);
        $notification = $recorder->record('notif-2', 'user-1', $request);

        self::assertSame('notif-2', $notification->getId());
        self::assertSame(NotificationChannel::PUSH, $notification->getChannel());
        self::assertSame(NotificationStatus::QUEUED, $notification->getStatus());
        self::assertSame('Welcome', $notification->getPayload()['title']);
        self::assertSame('Hi there', $notification->getPayload()['message']);
        self::assertSame(['url' => 'https://example.com'], $notification->getPayload()['data']);
    }

    #[Test]
    public function recordStoresAttachmentMetadataButNotTheContent(): void
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getReference')->willReturn(new User()->setUsername('owner'));
        $persisted = null;
        $entityManager->method('persist')->willReturnCallback(
            static function (object $entity) use (&$persisted): void {
                $persisted = $entity;
            },
        );

        $payload = new EmailNotificationPayload(
            'noreply@example.com',
            'Invoice',
            'body',
            null,
            [new EmailAttachment('invoice.pdf', 'application/pdf', 'attachments/fake', 9, 'inv')],
        );

        $request = new NotificationRequest($payload, ['ops@example.com']);
        new NotificationRecorder($entityManager)->record('notif-1', 'user-1', $request);

        $stored = $persisted->getPayload()['attachments'];
        self::assertSame([[
            'filename' => 'invoice.pdf',
            'contentType' => 'application/pdf',
            'contentId' => 'inv',
            'size' => 9,
            'path' => 'attachments/fake',
        ]], $stored);

        // The bytes must not be duplicated into the notification row, only
        // the on-disk path.
        self::assertStringNotContainsString('pdf-bytes', json_encode($persisted->getPayload()));
    }
}
