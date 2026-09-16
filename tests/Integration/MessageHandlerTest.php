<?php

declare(strict_types=1);

namespace Nofi\Tests\Integration;

use DateTimeImmutable;
use DomainException;
use Nofi\Notification\NotificationLifecycle;
use Nofi\Notification\Email\EmailNotificationPayload;
use Nofi\Notification\Push\PushNotificationPayload;
use Nofi\Entity\Notification;
use Nofi\Message\DeleteNotification;
use Nofi\Message\SendEmailNotification;
use Nofi\Message\SendPushNotification;
use Nofi\MessageHandler\DeleteNotificationHandler;
use Nofi\MessageHandler\SendNotificationHandler;
use Nofi\Notification\NotificationChannel;
use Nofi\Notification\NotificationStatus;
use Nofi\Notification\Push\PushService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mailer\Event\MessageEvent;

/**
 * What the handlers do when things are not in order: the notification is gone,
 * the provider refuses a token, the notification is already finished.
 */
#[TestDox("Message handlers under failure")]
final class MessageHandlerTest extends IntegrationTestCase
{
    use MailerAssertionsTrait;

    #[Test]
    public function anEmailForADeletedNotificationIsSkippedWithoutRetrying(): void
    {
        $handler = self::service(SendNotificationHandler::class);

        // No exception: retrying cannot bring the row back, so raising here
        // would only burn the retry budget and land in the failed transport.
        $handler(new SendEmailNotification('gone', $this->emailPayload()));

        self::assertSame([], $this->sentEmails());
    }

    #[Test]
    public function aPushForADeletedNotificationIsSkippedWithoutRetrying(): void
    {
        $pushService = $this->createMock(PushService::class);
        $pushService->expects(self::never())->method('send');
        self::getContainer()->set(PushService::class, $pushService);

        $handler = self::service(SendNotificationHandler::class);
        $handler(new SendPushNotification('gone', $this->pushPayload()));
    }

    #[Test]
    public function aPushRejectedByTheProviderMarksTheNotificationFailed(): void
    {
        $notification = $this->persistedNotification(['device-token-1'], NotificationChannel::PUSH);

        $pushService = $this->createStub(PushService::class);
        $pushService->method('send')->willReturn([
            ['token' => 'device-token-1', 'success' => false, 'error' => 'not a valid FCM registration token'],
        ]);
        self::getContainer()->set(PushService::class, $pushService);

        try {
            self::service(SendNotificationHandler::class)(
                new SendPushNotification($notification->getId(), $this->pushPayload()),
            );
            self::fail('Expected the rejected token to raise.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('failed for 1 of 1 device tokens', $e->getMessage());
        }

        self::entityManager()->clear();
        $reloaded = self::entityManager()->find(Notification::class, $notification->getId());
        self::assertSame(NotificationStatus::FAILED, $reloaded->getStatus());
    }

    #[Test]
    public function aClaimIsCommittedBeforeTheProviderIsCalledAndUnexpectedFailuresCanRetry(): void
    {
        $notification = $this->persistedNotification(['device-token-1'], NotificationChannel::PUSH);
        $id = $notification->getId();
        $attempt = 0;
        $pushService = $this->createStub(PushService::class);
        $pushService->method('send')->willReturnCallback(function () use ($id, &$attempt): array {
            $connection = self::entityManager()->getConnection();
            self::assertFalse($connection->isTransactionActive(), 'Provider calls must not hold the row lock.');
            self::assertSame('processing', $connection->fetchOne('SELECT status FROM notification WHERE id = ?', [$id]));
            self::assertNotNull($connection->fetchOne('SELECT processing_started_at FROM notification WHERE id = ?', [$id]));
            if (++$attempt === 1) {
                throw new RuntimeException('Provider connection failed');
            }

            return [['token' => 'device-token-1', 'success' => true, 'error' => null]];
        });
        self::getContainer()->set(PushService::class, $pushService);
        $handler = self::service(SendNotificationHandler::class);
        $message = new SendPushNotification($id, $this->pushPayload());

        try {
            $handler($message);
            self::fail('The provider error must reach Messenger.');
        } catch (RuntimeException $exception) {
            self::assertSame('Provider connection failed', $exception->getMessage());
        }

        $failed = self::entityManager()->find(Notification::class, $id);
        self::assertSame(NotificationStatus::FAILED, $failed->getStatus());
        self::assertSame(NotificationStatus::FAILED, $failed->getRecipients()->first()->getStatus());
        $handler($message);
        $sent = self::entityManager()->find(Notification::class, $id);
        self::assertSame(NotificationStatus::SENT, $sent->getStatus());
        self::assertSame(2, $attempt);
    }

    #[Test]
    public function templateFailuresReleaseTheClaimForMessengerToRetry(): void
    {
        $notification = $this->persistedNotification(['ops@example.com']);
        $id = $notification->getId();
        $message = new SendEmailNotification($id, new EmailNotificationPayload(
            'noreply@example.com',
            'Subject',
            'body',
            'template-that-does-not-exist',
        ));
        $handler = self::service(SendNotificationHandler::class);

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $handler($message);
                self::fail('A missing template must fail the send.');
            } catch (RuntimeException $exception) {
                self::assertStringContainsString('could not render template', $exception->getMessage());
            }
            $stored = self::entityManager()->find(Notification::class, $id);
            self::assertSame(NotificationStatus::FAILED, $stored->getStatus());
            self::assertNotNull($stored->getProcessingStartedAt());
        }
        self::assertSame([], $this->sentEmails());
    }

    #[Test]
    public function anExistingClaimIsNotDeliveredOrAcknowledgedAsComplete(): void
    {
        $notification = $this->persistedNotification(['device-token-1'], NotificationChannel::PUSH);
        self::service(NotificationLifecycle::class)->claimNotificationForDelivery($notification->getId());
        $pushService = $this->createMock(PushService::class);
        $pushService->expects(self::never())->method('send');
        self::getContainer()->set(PushService::class, $pushService);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already being processed');
        self::service(SendNotificationHandler::class)(
            new SendPushNotification($notification->getId(), $this->pushPayload()),
        );
    }

    #[Test]
    public function recordingAnUnexpectedFailurePreservesRecipientsAlreadySent(): void
    {
        $notification = $this->persistedNotification(['sent@example.com', 'failed@example.com']);
        $lifecycle = self::service(NotificationLifecycle::class);
        $claimed = $lifecycle->claimNotificationForDelivery($notification->getId());
        $claimed->getRecipients()[0]->markProcessing()->markSent();
        $claimed->getRecipients()[1]->markProcessing();

        $lifecycle->recordDeliveryFailure($claimed);

        $stored = self::entityManager()->find(Notification::class, $notification->getId());
        self::assertSame(NotificationStatus::FAILED, $stored->getStatus());
        self::assertSame(NotificationStatus::SENT, $stored->getRecipients()[0]->getStatus());
        self::assertSame(NotificationStatus::FAILED, $stored->getRecipients()[1]->getStatus());
    }

    #[Test]
    public function deletingRemovesANotificationThatHasNotBeenSent(): void
    {
        $notification = $this->persistedNotification(['ops@example.com']);
        $id = $notification->getId();

        self::service(DeleteNotificationHandler::class)(new DeleteNotification($id));

        self::entityManager()->clear();
        self::assertNull(self::entityManager()->find(Notification::class, $id));
    }

    #[Test]
    public function deletingLeavesAnAlreadySentNotificationAlone(): void
    {
        $notification = $this->persistedNotification(['ops@example.com']);
        $notification->markProcessing()->markSent();
        self::entityManager()->flush();
        $id = $notification->getId();

        self::service(DeleteNotificationHandler::class)(new DeleteNotification($id));

        self::entityManager()->clear();
        self::assertNotNull(
            self::entityManager()->find(Notification::class, $id),
            'a notification in a final state must be kept as a record of what was sent',
        );
    }

    #[Test]
    public function deletingSomethingThatIsAlreadyGoneIsHarmless(): void
    {
        self::service(DeleteNotificationHandler::class)(new DeleteNotification('never-existed'));

        self::assertTrue(true, 'no exception');
    }

    /**
     * @param list<string> $recipients
     */
    private function persistedNotification(
        array $recipients,
        NotificationChannel $channel = NotificationChannel::EMAIL,
    ): Notification {
        $notification = new Notification()
            ->assignCreatedBy($this->createUser('alice'))
            ->assignChannel($channel)
            ->recordCreatedAt(new DateTimeImmutable())
            ->markQueued();

        foreach ($recipients as $recipient) {
            $notification->addRecipient($recipient);
        }

        self::entityManager()->persist($notification);
        self::entityManager()->flush();

        return $notification;
    }

    private function emailPayload(): EmailNotificationPayload
    {
        return new EmailNotificationPayload('noreply@example.com', 'Subject', 'body', null);
    }

    private function pushPayload(): PushNotificationPayload
    {
        return new PushNotificationPayload('Title', 'body');
    }

    /**
     * @return list<object>
     */
    private function sentEmails(): array
    {
        return array_values(array_filter(
            array_map(
                static fn (MessageEvent $event): object => $event->getMessage(),
                self::getMailerEvents(),
            ),
        ));
    }
}
