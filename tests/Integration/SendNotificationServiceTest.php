<?php

declare(strict_types=1);

namespace Nofi\Tests\Integration;

use DateTimeImmutable;
use Nofi\Notification\SendNotificationService;
use Nofi\Notification\NotificationRequest;
use Nofi\Notification\Email\EmailAttachment;
use Nofi\Notification\Email\EmailNotificationPayload;
use Nofi\Notification\Push\PushNotificationPayload;
use Nofi\Entity\Notification;
use Nofi\Message\SendEmailNotification;
use Nofi\Message\SendPushNotification;
use Nofi\Notification\NotificationChannel;
use Nofi\Notification\NotificationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Recording and dispatch together, against a real database and a real bus.
 */
#[TestDox("SendNotificationService")]
final class SendNotificationServiceTest extends IntegrationTestCase
{
    #[Test]
    public function sendingRecordsTheNotificationAndQueuesAnEmailMessage(): void
    {
        $user = $this->createUser('alice');

        $id = self::service(SendNotificationService::class)->send($user->getId(), $this->emailRequest())->getId();

        $notification = self::entityManager()->find(Notification::class, $id);
        self::assertNotNull($notification);
        self::assertSame(NotificationStatus::QUEUED, $notification->getStatus());
        self::assertSame(NotificationChannel::EMAIL, $notification->getChannel());
        self::assertSame('alice', $notification->getCreatedBy()->getUserIdentifier());
        self::assertCount(2, $notification->getRecipients());

        $messages = $this->transport()->getSent();
        self::assertCount(1, $messages);
        self::assertInstanceOf(SendEmailNotification::class, $messages[0]->getMessage());
        self::assertSame($id, $messages[0]->getMessage()->getNotificationId());
    }

    #[Test]
    public function aPushSendQueuesAPushMessage(): void
    {
        $user = $this->createUser('alice');

        $request = new NotificationRequest(
            new PushNotificationPayload('Deployment finished', 'Build 42 is live'),
            ['device-token-1'],
        );

        self::service(SendNotificationService::class)->send($user->getId(), $request);

        self::assertInstanceOf(
            SendPushNotification::class,
            $this->transport()->getSent()[0]->getMessage(),
        );
    }

    #[Test]
    public function aFutureScheduleIsDispatchedWithADelay(): void
    {
        $user = $this->createUser('alice');
        $request = $this->emailRequest(new DateTimeImmutable('+1 hour'));

        self::service(SendNotificationService::class)->send($user->getId(), $request);

        $stamps = $this->transport()->getSent()[0]->all(DelayStamp::class);
        self::assertCount(1, $stamps);
        // Just under the full hour, so a second or two of clock drift between
        // building the DTO and dispatching does not make this flaky.
        $almostAnHour = (int) ((new DateTimeImmutable('+59 minutes'))->getTimestamp() - time()) * 1000;
        self::assertGreaterThan($almostAnHour, $stamps[0]->getDelay());
    }

    #[Test]
    public function aScheduleInThePastIsDispatchedImmediately(): void
    {
        $user = $this->createUser('alice');
        $request = $this->emailRequest(new DateTimeImmutable('-1 hour'));

        self::service(SendNotificationService::class)->send($user->getId(), $request);

        self::assertSame([], $this->transport()->getSent()[0]->all(DelayStamp::class));
    }

    #[Test]
    public function attachmentsAreRecordedAsMetadataOnly(): void
    {
        $user = $this->createUser('alice');
        $request = new NotificationRequest(
            new EmailNotificationPayload('noreply@example.com', 'Invoice', 'body', null, [
                new EmailAttachment('invoice.pdf', 'application/pdf', 'pdf-bytes'),
            ]),
            ['ops@example.com'],
        );

        $id = self::service(SendNotificationService::class)->send($user->getId(), $request)->getId();

        $payload = self::entityManager()->find(Notification::class, $id)->getPayload();
        self::assertSame(
            [[
                'filename' => 'invoice.pdf',
                'contentType' => 'application/pdf',
                'contentId' => null,
                'size' => strlen('pdf-bytes'),
            ]],
            $payload['attachments'],
        );
    }

    private function emailRequest(?DateTimeImmutable $scheduledAt = null): NotificationRequest
    {
        return new NotificationRequest(
            new EmailNotificationPayload('noreply@example.com', 'Deployment finished', '<p>Build 42 is live</p>', null),
            ['ops@example.com', 'dev@example.com'],
            $scheduledAt,
        );
    }

    private function transport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.async');
    }
}
