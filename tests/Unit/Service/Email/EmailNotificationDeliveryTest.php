<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Service\Email;

use Doctrine\ORM\EntityManagerInterface;
use Nofi\Entity\Notification;
use Nofi\Notification\EmailAttachment;
use Nofi\Notification\EmailNotificationPayload;
use Nofi\Notification\NotificationStatus;
use Nofi\Service\Email\EmailNotificationDelivery;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Nofi\Notification\MailTemplateLocator;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[TestDox("EmailNotificationDelivery")]
final class EmailNotificationDeliveryTest extends TestCase
{
    #[Test]
    public function deliverMarksEveryRecipientSentWhenTheMailerAccepts(): void
    {
        $notification = $this->notificationFor(['ops@example.com', 'dev@example.com']);

        $this->deliveryWith($this->mailerFailingFor([]))->deliver($notification, $this->payload());

        self::assertSame(NotificationStatus::SENT, $notification->getStatus());
        foreach ($notification->getRecipients() as $recipient) {
            self::assertSame(NotificationStatus::SENT, $recipient->getStatus());
            self::assertNotNull($recipient->getSentAt());
        }
    }

    #[Test]
    public function deliverLogsAndReportsAPartialFailure(): void
    {
        $notification = $this->notificationFor(['ops@example.com', 'broken@example.com']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('Email notification delivery failed'),
                self::callback(static fn (array $c): bool => $c['recipient'] === 'broken@example.com'
                    && $c['exception'] instanceof RuntimeException),
            );

        $delivery = $this->deliveryWith($this->mailerFailingFor(['broken@example.com']), $logger);

        try {
            $delivery->deliver($notification, $this->payload());
            self::fail('Expected a RuntimeException for the failed recipient.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('failed for 1 of 2 recipients', $e->getMessage());
        }

        self::assertSame(NotificationStatus::FAILED, $notification->getStatus());

        $byAddress = [];
        foreach ($notification->getRecipients() as $recipient) {
            $byAddress[$recipient->getRecipient()] = $recipient->getStatus();
        }
        self::assertSame(NotificationStatus::SENT, $byAddress['ops@example.com']);
        self::assertSame(NotificationStatus::FAILED, $byAddress['broken@example.com']);
    }

    #[Test]
    public function deliverSkipsRecipientsThatWereAlreadySentOnAnEarlierAttempt(): void
    {
        $notification = $this->notificationFor(['already@example.com', 'pending@example.com']);
        foreach ($notification->getRecipients() as $recipient) {
            if ($recipient->getRecipient() === 'already@example.com') {
                $recipient->markProcessing()->markSent();
            }
        }

        $addressed = [];
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (RawMessage $message) use (&$addressed): void {
                $addressed[] = $message->getTo()[0]->getAddress();
            },
        );

        $this->deliveryWith($mailer)->deliver($notification, $this->payload());

        self::assertSame(['pending@example.com'], $addressed);
        self::assertSame(NotificationStatus::SENT, $notification->getStatus());
    }

    private function notificationFor(array $recipients): Notification
    {
        $notification = new Notification('notif-1')->markQueued();
        foreach ($recipients as $recipient) {
            $notification->addRecipient($recipient);
        }

        return $notification;
    }

    private function payload(): EmailNotificationPayload
    {
        return new EmailNotificationPayload(
            'noreply@example.com',
            'Deployment finished',
            '<h1>Build 42 is live</h1>',
            null,
        );
    }

    /**
     * @param list<string> $failingAddresses
     */
    private function mailerFailingFor(array $failingAddresses): MailerInterface
    {
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (RawMessage $message) use ($failingAddresses): void {
                if (in_array($message->getTo()[0]->getAddress(), $failingAddresses, true)) {
                    throw new RuntimeException('smtp is unreachable');
                }
            },
        );

        return $mailer;
    }

    private function deliveryWith(
        MailerInterface $mailer,
        ?LoggerInterface $logger = null,
        array $templates = [],
    ): EmailNotificationDelivery {
        return new EmailNotificationDelivery(
            $this->createStub(EntityManagerInterface::class),
            $mailer,
            $logger ?? $this->createStub(LoggerInterface::class),
            new MailTemplateLocator(new Environment(new ArrayLoader($templates))),
        );
    }

    #[Test]
    public function deliverAttachesFilesToTheEmail(): void
    {
        $notification = $this->notificationFor(['ops@example.com']);
        $sent = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (RawMessage $message) use (&$sent): void { $sent = $message; },
        );

        $payload = new EmailNotificationPayload(
            'noreply@example.com',
            'Invoice 4711',
            '<p>See attached</p>',
            null,
            [new EmailAttachment('invoice-4711.pdf', 'application/pdf', 'pdf-bytes')],
        );

        $this->deliveryWith($mailer)->deliver($notification, $payload);

        self::assertInstanceOf(Email::class, $sent);
        $attachments = $sent->getAttachments();
        self::assertCount(1, $attachments);
        self::assertSame('invoice-4711.pdf', $attachments[0]->getName());
        self::assertSame('pdf-bytes', $attachments[0]->getBody());
        self::assertSame('application/pdf', $attachments[0]->getMediaType() . '/' . $attachments[0]->getMediaSubtype());

        // Without a contentId the part must be a normal attachment, not inline.
        $rendered = $sent->toString();
        self::assertStringContainsString('Content-Disposition: attachment', $rendered);
        self::assertStringNotContainsString('Content-Disposition: inline', $rendered);
    }

    #[Test]
    public function deliverEmbedsAttachmentsThatCarryAContentId(): void
    {
        $notification = $this->notificationFor(['ops@example.com']);
        $sent = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (RawMessage $message) use (&$sent): void { $sent = $message; },
        );

        $payload = new EmailNotificationPayload(
            'noreply@example.com',
            'Welcome',
            '<p>Hi</p><img src="cid:logo">',
            null,
            [new EmailAttachment('logo.png', 'image/png', 'png-bytes', 'logo')],
        );

        $this->deliveryWith($mailer)->deliver($notification, $payload);

        self::assertInstanceOf(Email::class, $sent);

        // The rendered message is what proves the embedding actually happened:
        // Symfony rewrites cid:logo to the generated Content-ID and marks the
        // part inline.
        $rendered = $sent->toString();
        self::assertStringContainsString('Content-Disposition: inline', $rendered);
        self::assertStringNotContainsString('src="cid:logo"', $rendered);
        self::assertMatchesRegularExpression('/Content-ID: <[^>]+>/', $rendered);
    }

    #[Test]
    public function deliverSendsBothAnInlineImageAndARegularAttachment(): void
    {
        $notification = $this->notificationFor(['ops@example.com']);
        $sent = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (RawMessage $message) use (&$sent): void { $sent = $message; },
        );

        $payload = new EmailNotificationPayload(
            'noreply@example.com',
            'Invoice 4711',
            '<p>See attached</p><img src="cid:logo">',
            null,
            [
                new EmailAttachment('logo.png', 'image/png', 'png-bytes', 'logo'),
                new EmailAttachment('invoice.pdf', 'application/pdf', 'pdf-bytes'),
            ],
        );

        $this->deliveryWith($mailer)->deliver($notification, $payload);

        $rendered = $sent->toString();
        self::assertStringContainsString('Content-Disposition: inline', $rendered);
        self::assertStringContainsString('invoice.pdf', $rendered);
        self::assertSame(NotificationStatus::SENT, $notification->getStatus());
    }

    #[Test]
    public function deliverUsesTheMessageAsTheBodyWhenNoTemplateIsGiven(): void
    {
        $notification = $this->notificationFor(['ops@example.com']);
        $sent = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (RawMessage $m) use (&$sent): void { $sent = $m; },
        );

        $this->deliveryWith($mailer)->deliver($notification, $this->payload());

        self::assertSame('<h1>Build 42 is live</h1>', $sent->getHtmlBody());
    }

    #[Test]
    public function deliverRendersTheTemplateWithSubjectMessageAndData(): void
    {
        $notification = $this->notificationFor(['ops@example.com']);
        $sent = null;
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (RawMessage $m) use (&$sent): void { $sent = $m; },
        );

        $payload = new EmailNotificationPayload(
            'noreply@example.com',
            'Deployment finished',
            '<p>Build 42</p>',
            'welcome',
            [],
            ['customerName' => 'Ada'],
        );

        $delivery = $this->deliveryWith($mailer, null, [
            '@mail/welcome.html.twig' => '<h1>{{ subject }}</h1>{{ message|raw }}<p>{{ customerName }}</p>',
        ]);
        $delivery->deliver($notification, $payload);

        self::assertSame(
            '<h1>Deployment finished</h1><p>Build 42</p><p>Ada</p>',
            $sent->getHtmlBody(),
        );
        self::assertSame(NotificationStatus::SENT, $notification->getStatus());
    }

    #[Test]
    public function deliverFailsTheWholeNotificationWhenTheTemplateCannotRender(): void
    {
        $notification = $this->notificationFor(['ops@example.com']);
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('template failed to render'), self::anything());

        $payload = new EmailNotificationPayload(
            'noreply@example.com',
            'Subject',
            'body',
            'broken',
            [],
        );

        $delivery = $this->deliveryWith($mailer, $logger, [
            '@mail/broken.html.twig' => '{{ this is not valid twig',
        ]);

        $this->expectException(RuntimeException::class);
        $delivery->deliver($notification, $payload);
    }
}
