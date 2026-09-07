<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Notification;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nofi\Dto\SendNotificationDto;
use Nofi\Entity\User;
use Nofi\Message\SendEmailNotification;
use Nofi\Message\SendPushNotification;
use Nofi\Notification\EmailAttachment;
use Nofi\Notification\EmailNotificationPayload;
use Nofi\Notification\NotificationChannel;
use Nofi\Notification\NotificationRecorder;
use Nofi\Notification\NotificationStatus;
use Nofi\Notification\PushNotificationPayload;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox("NotificationRecorder behavior")]
final class NotificationRecorderTest extends TestCase
{
    #[Test]
    public function recordsEmailNotificationWithEmailPayload(): void
    {
        $dto = new SendNotificationDto();
        $dto->channel = NotificationChannel::EMAIL;
        $dto->sender = 'noreply@example.com';
        $dto->subject = 'Hello';
        $dto->message = 'Hi there';
        $dto->recipients = ['alice@example.com'];

        $message = new SendEmailNotification('notif-1', $dto, 'user-1');
        $payload = EmailNotificationPayload::fromDto($dto);

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
        $notification = $recorder->record($message, $payload);

        self::assertSame('notif-1', $notification->getId());
        self::assertSame(NotificationChannel::EMAIL, $notification->getChannel());
        self::assertSame(NotificationStatus::QUEUED, $notification->getStatus());
        self::assertSame('noreply@example.com', $notification->getPayload()['sender']);
        self::assertSame('Hello', $notification->getPayload()['subject']);
    }

    #[Test]
    public function recordsPushNotificationWithPushPayload(): void
    {
        $dto = new SendNotificationDto();
        $dto->channel = NotificationChannel::PUSH;
        $dto->title = 'Welcome';
        $dto->message = 'Hi there';
        $dto->data = ['url' => 'https://example.com'];
        $dto->tokens = ['device-token-1'];

        $message = new SendPushNotification('notif-2', $dto, 'user-1');
        $payload = PushNotificationPayload::fromDto($dto);

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
        $notification = $recorder->record($message, $payload);

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
            static function (object $entity) use (&$persisted): void { $persisted = $entity; },
        );

        $dto = new SendNotificationDto();
        $dto->channel = NotificationChannel::EMAIL;
        $dto->sender = 'noreply@example.com';
        $dto->subject = 'Invoice';
        $dto->message = 'body';
        $dto->recipients = ['ops@example.com'];

        $payload = new EmailNotificationPayload(
            'noreply@example.com',
            'Invoice',
            'body',
            null,
            [new EmailAttachment('invoice.pdf', 'application/pdf', 'pdf-bytes', 'inv')],
        );

        $message = new SendEmailNotification('notif-1', $dto, 'user-1');
        new NotificationRecorder($entityManager)->record($message, $payload);

        $stored = $persisted->getPayload()['attachments'];
        self::assertSame([[
            'filename' => 'invoice.pdf',
            'contentType' => 'application/pdf',
            'contentId' => 'inv',
            'size' => 9,
        ]], $stored);

        // The bytes must not be duplicated into the notification row.
        self::assertStringNotContainsString('pdf-bytes', json_encode($persisted->getPayload()));
    }
}
