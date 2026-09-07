<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Notification;

use Nofi\Dto\SendNotificationDto;
use Nofi\Entity\Notification;
use Nofi\Message\SendPushNotification;
use Nofi\MessageHandler\SendPushNotificationHandler;
use Nofi\Notification\PushNotificationPayload;
use Nofi\Service\Push\PushNotificationDelivery;
use Nofi\Repository\NotificationRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[TestDox("SendPushNotificationHandler behavior")]
final class SendPushNotificationHandlerTest extends TestCase
{
    #[Test]
    public function invokeLoadsAndDeliversAPushNotification(): void
    {
        $dto = new SendNotificationDto();
        $dto->title = 'Welcome';
        $dto->message = 'Hi there';
        $dto->data = ['url' => 'https://example.com'];
        $dto->tokens = ['device-token-1'];

        $message = new SendPushNotification('notif-1', $dto, 'user-1');
        $notification = new Notification('notif-1');

        $repository = $this->createMock(NotificationRepository::class);
        $delivery = $this->createMock(PushNotificationDelivery::class);

        $repository->expects(self::once())
            ->method('find')
            ->with('notif-1')
            ->willReturn($notification);

        $delivery->expects(self::once())
            ->method('deliver')
            ->with(
                $notification,
                self::callback(static function (PushNotificationPayload $payload): bool {
                    return $payload->title === 'Welcome'
                        && $payload->message === 'Hi there'
                        && $payload->data === ['url' => 'https://example.com'];
                }),
            );

        $handler = new SendPushNotificationHandler($repository, $delivery, $this->createStub(LoggerInterface::class));
        $handler($message);
    }

    #[Test]
    public function invokeSkipsANotificationThatWasDeletedBeforeItWasConsumed(): void
    {
        $message = new SendPushNotification('gone', new SendNotificationDto(), 'user-1');

        $repository = $this->createStub(NotificationRepository::class);
        $repository->method('find')->willReturn(null);

        $delivery = $this->createMock(PushNotificationDelivery::class);
        $delivery->expects(self::never())->method('deliver');

        $handler = new SendPushNotificationHandler(
            $repository,
            $delivery,
            $this->createStub(LoggerInterface::class),
        );

        $handler($message);
    }
}
