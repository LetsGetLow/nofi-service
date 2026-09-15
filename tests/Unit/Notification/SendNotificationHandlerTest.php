<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Notification;

use Nofi\Entity\Notification;
use Nofi\Message\SendEmailNotification;
use Nofi\Message\SendPushNotification;
use Nofi\MessageHandler\SendNotificationHandler;
use Nofi\Notification\Email\EmailNotificationPayload;
use Nofi\Notification\NotificationChannel;
use Nofi\Notification\NotificationDeliveries;
use Nofi\Notification\NotificationDelivery;
use Nofi\Notification\Push\PushNotificationPayload;
use Nofi\Repository\NotificationRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * One handler serves every channel, so these tests are the pair that used to
 * live in SendEmailNotificationHandlerTest and SendPushNotificationHandlerTest
 * and differed only in their type names.
 */
#[TestDox("SendNotificationHandler behavior")]
final class SendNotificationHandlerTest extends TestCase
{
    #[Test]
    public function invokeLoadsAndDeliversAnEmailNotification(): void
    {
        $payload = new EmailNotificationPayload('noreply@example.com', 'Hello', 'Hi there', null);

        $notification = new Notification('notif-1');
        $delivery = $this->deliveryFor(NotificationChannel::EMAIL);

        $delivery->expects(self::once())
            ->method('deliver')
            ->with(
                $notification,
                self::callback(static function (EmailNotificationPayload $payload): bool {
                    return $payload->sender === 'noreply@example.com'
                        && $payload->subject === 'Hello'
                        && $payload->message === 'Hi there';
                }),
            );

        $this->handlerFor($notification, $delivery)(
            new SendEmailNotification('notif-1', $payload),
        );
    }

    #[Test]
    public function invokeLoadsAndDeliversAPushNotification(): void
    {
        $payload = new PushNotificationPayload('Order shipped', 'On its way');

        $notification = new Notification('notif-2')->assignChannel(NotificationChannel::PUSH);
        $delivery = $this->deliveryFor(NotificationChannel::PUSH);

        $delivery->expects(self::once())
            ->method('deliver')
            ->with(
                $notification,
                self::callback(static function (PushNotificationPayload $payload): bool {
                    return $payload->title === 'Order shipped'
                        && $payload->message === 'On its way';
                }),
            );

        $this->handlerFor($notification, $delivery)(
            new SendPushNotification('notif-2', $payload),
        );
    }

    #[Test]
    public function invokeSkipsANotificationThatWasDeletedBeforeItWasConsumed(): void
    {
        $delivery = $this->deliveryFor(NotificationChannel::EMAIL);
        $delivery->expects(self::never())->method('deliver');

        $repository = $this->createStub(NotificationRepository::class);
        $repository->method('find')->willReturn(null);

        $handler = new SendNotificationHandler(
            $repository,
            new NotificationDeliveries([$delivery]),
            $this->createStub(LoggerInterface::class),
        );

        $handler(new SendEmailNotification('gone', new EmailNotificationPayload('sender@example.com', 'Subject', 'Body', null)));
    }

    #[Test]
    public function aPayloadForAnotherChannelIsRejectedBeforeDelivery(): void
    {
        $notification = new Notification('notif-1')->assignChannel(NotificationChannel::EMAIL);
        $delivery = $this->deliveryFor(NotificationChannel::PUSH);
        $delivery->expects(self::never())->method('deliver');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('different channel from its queued payload');

        $this->handlerFor($notification, $delivery)(
            new SendPushNotification('notif-1', new PushNotificationPayload('Title', 'Body')),
        );
    }

    /**
     * @return NotificationDelivery&MockObject
     */
    private function deliveryFor(NotificationChannel $channel): NotificationDelivery
    {
        $delivery = $this->createMock(NotificationDelivery::class);
        $delivery->method('channel')->willReturn($channel);

        return $delivery;
    }

    private function handlerFor(
        Notification $notification,
        NotificationDelivery $delivery,
    ): SendNotificationHandler {
        $repository = $this->createMock(NotificationRepository::class);
        $repository->expects(self::once())
            ->method('find')
            ->with($notification->getId())
            ->willReturn($notification);

        return new SendNotificationHandler(
            $repository,
            new NotificationDeliveries([$delivery]),
            $this->createStub(LoggerInterface::class),
        );
    }
}
