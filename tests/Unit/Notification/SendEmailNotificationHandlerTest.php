<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Notification;

use Nofi\Dto\SendNotificationDto;
use Nofi\Entity\Notification;
use Nofi\Message\SendEmailNotification;
use Nofi\MessageHandler\SendEmailNotificationHandler;
use Nofi\Notification\EmailNotificationPayload;
use Nofi\Service\Email\EmailNotificationDelivery;
use Nofi\Repository\NotificationRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

#[TestDox("SendEmailNotificationHandler behavior")]
final class SendEmailNotificationHandlerTest extends TestCase
{
    #[Test]
    public function invokeLoadsAndDeliversAnEmailNotification(): void
    {
        $dto = new SendNotificationDto();
        $dto->sender = 'noreply@example.com';
        $dto->subject = 'Hello';
        $dto->message = 'Hi there';
        $dto->recipients = ['alice@example.com'];

        $message = new SendEmailNotification('notif-1', $dto, 'user-1');
        $notification = new Notification('notif-1');

        $repository = $this->createMock(NotificationRepository::class);
        $delivery = $this->createMock(EmailNotificationDelivery::class);

        $repository->expects(self::once())
            ->method('find')
            ->with('notif-1')
            ->willReturn($notification);

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

        $handler = new SendEmailNotificationHandler($repository, $delivery, $this->createStub(LoggerInterface::class));
        $handler($message);
    }

    #[Test]
    public function invokeSkipsANotificationThatWasDeletedBeforeItWasConsumed(): void
    {
        $message = new SendEmailNotification('gone', new SendNotificationDto(), 'user-1');

        $repository = $this->createStub(NotificationRepository::class);
        $repository->method('find')->willReturn(null);

        $delivery = $this->createMock(EmailNotificationDelivery::class);
        $delivery->expects(self::never())->method('deliver');

        $handler = new SendEmailNotificationHandler(
            $repository,
            $delivery,
            $this->createStub(LoggerInterface::class),
        );

        $handler($message);
    }
}
