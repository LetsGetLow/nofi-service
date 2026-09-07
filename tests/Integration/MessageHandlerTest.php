<?php

declare(strict_types=1);

namespace Nofi\Tests\Integration;

use DateTimeImmutable;
use Nofi\Dto\SendNotificationDto;
use Nofi\Entity\Notification;
use Nofi\Message\DeleteNotification;
use Nofi\Message\SendEmailNotification;
use Nofi\Message\SendPushNotification;
use Nofi\MessageHandler\DeleteNotificationHandler;
use Nofi\MessageHandler\SendNotificationHandler;
use Nofi\Notification\NotificationChannel;
use Nofi\Notification\NotificationStatus;
use Nofi\Service\Push\PushService;
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
        $handler(new SendEmailNotification('gone', $this->emailDto(), 'user-1'));

        self::assertSame([], $this->sentEmails());
    }

    #[Test]
    public function aPushForADeletedNotificationIsSkippedWithoutRetrying(): void
    {
        $pushService = $this->createMock(PushService::class);
        $pushService->expects(self::never())->method('send');
        self::getContainer()->set(PushService::class, $pushService);

        $handler = self::service(SendNotificationHandler::class);
        $handler(new SendPushNotification('gone', $this->pushDto(), 'user-1'));
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
                new SendPushNotification($notification->getId(), $this->pushDto(), 'user-1'),
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

    private function emailDto(): SendNotificationDto
    {
        $dto = new SendNotificationDto();
        $dto->channel = NotificationChannel::EMAIL;
        $dto->sender = 'noreply@example.com';
        $dto->subject = 'Subject';
        $dto->message = 'body';
        $dto->recipients = ['ops@example.com'];

        return $dto;
    }

    private function pushDto(): SendNotificationDto
    {
        $dto = new SendNotificationDto();
        $dto->channel = NotificationChannel::PUSH;
        $dto->title = 'Title';
        $dto->message = 'body';
        $dto->tokens = ['device-token-1'];

        return $dto;
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
