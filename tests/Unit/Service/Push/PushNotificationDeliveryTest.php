<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Service\Push;

use Doctrine\ORM\EntityManagerInterface;
use Nofi\Entity\Notification;
use Nofi\Notification\NotificationStatus;
use Nofi\Notification\PushNotificationPayload;
use Nofi\Service\Push\PushNotificationDelivery;
use Nofi\Service\Push\PushService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

#[TestDox("PushNotificationDelivery")]
final class PushNotificationDeliveryTest extends TestCase
{
    #[Test]
    public function deliverMarksEveryTokenSentWhenFirebaseAcceptsThem(): void
    {
        $notification = $this->notificationFor(['token-a', 'token-b']);

        $this->deliveryWith($this->pushServiceFailingFor([]))->deliver($notification, $this->payload());

        self::assertSame(NotificationStatus::SENT, $notification->getStatus());
        foreach ($notification->getRecipients() as $recipient) {
            self::assertSame(NotificationStatus::SENT, $recipient->getStatus());
            self::assertNotNull($recipient->getSentAt());
        }
    }

    #[Test]
    public function deliverLogsAndReportsAPartialFailure(): void
    {
        $notification = $this->notificationFor(['token-a', 'token-broken']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('Push notification delivery failed'),
                self::callback(static fn (array $c): bool => $c['token'] === 'token-broken'
                    && $c['error'] === 'token is not registered'),
            );

        $delivery = $this->deliveryWith($this->pushServiceFailingFor(['token-broken']), $logger);

        try {
            $delivery->deliver($notification, $this->payload());
            self::fail('Expected a RuntimeException for the failed token.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('failed for 1 of 2 device tokens', $e->getMessage());
        }

        self::assertSame(NotificationStatus::FAILED, $notification->getStatus());
    }

    #[Test]
    public function deliverSendsNothingWhenEveryTokenWasAlreadyDelivered(): void
    {
        $notification = $this->notificationFor(['token-a']);
        foreach ($notification->getRecipients() as $recipient) {
            $recipient->markProcessing()->markSent();
        }

        $pushService = $this->createMock(PushService::class);
        $pushService->expects(self::never())->method('send');

        $this->deliveryWith($pushService)->deliver($notification, $this->payload());

        self::assertSame(NotificationStatus::SENT, $notification->getStatus());
    }

    #[Test]
    public function aRecipientTheProviderDidNotReportOnIsLeftUntouched(): void
    {
        $notification = $this->notificationFor(['token-a', 'token-b']);

        // Only one of the two tokens comes back, which must not be read as
        // success or failure for the other.
        $pushService = $this->createStub(PushService::class);
        $pushService->method('send')->willReturn([
            ['token' => 'token-a', 'success' => true, 'error' => null],
        ]);

        $this->deliveryWith($pushService)->deliver($notification, $this->payload());

        $statuses = [];
        foreach ($notification->getRecipients() as $recipient) {
            $statuses[$recipient->getRecipient()] = $recipient->getStatus();
        }
        self::assertSame(NotificationStatus::SENT, $statuses['token-a']);
        self::assertSame(NotificationStatus::PROCESSING, $statuses['token-b']);
    }

    private function notificationFor(array $tokens): Notification
    {
        $notification = new Notification('notif-1')->markQueued();
        foreach ($tokens as $token) {
            $notification->addRecipient($token);
        }

        return $notification;
    }

    private function payload(): PushNotificationPayload
    {
        return new PushNotificationPayload('Deployment finished', 'Build 42 is live');
    }

    /**
     * @param list<string> $failingTokens
     */
    private function pushServiceFailingFor(array $failingTokens): PushService
    {
        $pushService = $this->createStub(PushService::class);
        $pushService->method('send')->willReturnCallback(
            static function (Notification $n, array $tokens) use ($failingTokens): array {
                $results = [];
                foreach ($tokens as $token) {
                    $failed = in_array($token, $failingTokens, true);
                    $results[] = [
                        'token' => $token,
                        'success' => !$failed,
                        'error' => $failed ? 'token is not registered' : null,
                    ];
                }

                return $results;
            },
        );

        return $pushService;
    }

    private function deliveryWith(
        PushService $pushService,
        ?LoggerInterface $logger = null,
    ): PushNotificationDelivery {
        return new PushNotificationDelivery(
            $pushService,
            $this->createStub(EntityManagerInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }
}
