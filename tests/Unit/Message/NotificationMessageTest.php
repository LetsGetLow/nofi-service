<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Message;

use Nofi\Message\SendEmailNotification;
use Nofi\Message\SendPushNotification;
use Nofi\Notification\Email\EmailAttachment;
use Nofi\Notification\Email\EmailNotificationPayload;
use Nofi\Notification\Push\PushNotificationPayload;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;

final class NotificationMessageTest extends TestCase
{
    #[Test]
    public function emailSurvivesQueueSerializationWithBinaryAttachmentContent(): void
    {
        $payload = new EmailNotificationPayload('sender@example.com', 'Subject', 'Body', 'welcome', [
            new EmailAttachment('logo.png', 'image/png', "\x00\xff\x80image", 'logo'),
        ], ['name' => 'Alice']);
        $message = new SendEmailNotification('notification-1', $payload);
        $serializer = new PhpSerializer();

        $encoded = $serializer->encode(new Envelope($message, [new DelayStamp(1000)]));
        $decoded = $serializer->decode($encoded);
        $restored = $decoded->getMessage();

        self::assertInstanceOf(SendEmailNotification::class, $restored);
        self::assertSame('notification-1', $restored->getNotificationId());
        self::assertEquals($payload, $restored->getPayload());
        self::assertSame("\x00\xff\x80image", $restored->getPayload()->attachments[0]->content);
        self::assertSame('logo', $restored->getPayload()->attachments[0]->contentId);
        self::assertSame(1000, $decoded->last(DelayStamp::class)->getDelay());
        self::assertStringNotContainsString('Nofi\\Dto\\', serialize($restored));
    }

    #[Test]
    public function pushSurvivesQueueSerializationWithStructuredData(): void
    {
        $payload = new PushNotificationPayload('Title', 'Body', 'https://example.com/icon.png', [
            'orderId' => 42,
            'urgent' => true,
            'reference' => null,
            'meta' => ['attempt' => 1],
        ]);
        $serializer = new PhpSerializer();

        $restored = $serializer->decode($serializer->encode(
            new Envelope(new SendPushNotification('notification-2', $payload)),
        ))->getMessage();

        self::assertInstanceOf(SendPushNotification::class, $restored);
        self::assertSame('notification-2', $restored->getNotificationId());
        self::assertEquals($payload, $restored->getPayload());
        self::assertStringNotContainsString('Nofi\\Dto\\', serialize($restored));
    }
}
