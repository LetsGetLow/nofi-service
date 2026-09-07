<?php

declare(strict_types=1);

namespace Nofi\Tests\Integration;

use DateTimeImmutable;
use Nofi\Application\Notification\SendNotificationService;
use Nofi\Dto\SendNotificationDto;
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

        $id = self::service(SendNotificationService::class)->send($user, $this->emailDto())->getId();

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

        $dto = new SendNotificationDto();
        $dto->channel = NotificationChannel::PUSH;
        $dto->title = 'Deployment finished';
        $dto->message = 'Build 42 is live';
        $dto->recipients = ['device-token-1'];

        self::service(SendNotificationService::class)->send($user, $dto);

        self::assertInstanceOf(
            SendPushNotification::class,
            $this->transport()->getSent()[0]->getMessage(),
        );
    }

    #[Test]
    public function aFutureScheduleIsDispatchedWithADelay(): void
    {
        $user = $this->createUser('alice');
        $dto = $this->emailDto();
        $dto->scheduledAt = new DateTimeImmutable('+1 hour');

        self::service(SendNotificationService::class)->send($user, $dto);

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
        $dto = $this->emailDto();
        $dto->scheduledAt = new DateTimeImmutable('-1 hour');

        self::service(SendNotificationService::class)->send($user, $dto);

        self::assertSame([], $this->transport()->getSent()[0]->all(DelayStamp::class));
    }

    #[Test]
    public function attachmentsAreRecordedAsMetadataOnly(): void
    {
        $user = $this->createUser('alice');
        $dto = $this->emailDto();
        $attachment = new \Nofi\Dto\AttachmentDto();
        $attachment->filename = 'invoice.pdf';
        $attachment->contentType = 'application/pdf';
        $attachment->content = base64_encode('pdf-bytes');
        $dto->attachments = [$attachment];

        $id = self::service(SendNotificationService::class)->send($user, $dto)->getId();

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

    private function emailDto(): SendNotificationDto
    {
        $dto = new SendNotificationDto();
        $dto->channel = NotificationChannel::EMAIL;
        $dto->sender = 'noreply@example.com';
        $dto->subject = 'Deployment finished';
        $dto->message = '<p>Build 42 is live</p>';
        $dto->recipients = ['ops@example.com', 'dev@example.com'];

        return $dto;
    }

    private function transport(): InMemoryTransport
    {
        return self::getContainer()->get('messenger.transport.async');
    }
}
