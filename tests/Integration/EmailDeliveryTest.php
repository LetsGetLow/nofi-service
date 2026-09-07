<?php

declare(strict_types=1);

namespace Nofi\Tests\Integration;

use Nofi\Entity\Notification;
use Nofi\Entity\User;
use Nofi\Notification\EmailAttachment;
use Nofi\Notification\EmailNotificationPayload;
use Nofi\Notification\MailTemplateLocator;
use Nofi\Notification\NotificationStatus;
use Nofi\Service\Email\EmailNotificationDelivery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Mime\Email;

/**
 * Delivery wired through the real container: the configured Twig loader, the
 * real template directory and the real mailer.
 */
#[TestDox("Email delivery through the container")]
final class EmailDeliveryTest extends IntegrationTestCase
{
    use MailerAssertionsTrait;

    #[Test]
    public function theConfiguredLoaderResolvesTemplatesInTheMailDirectory(): void
    {
        $locator = self::service(MailTemplateLocator::class);

        self::assertSame('@mail/example.html.twig', $locator->reference('example'));
        self::assertTrue($locator->exists('example'));
        self::assertFalse($locator->exists('does-not-exist'));
    }

    #[Test]
    public function aTemplateNameCannotReachOutsideTheMailDirectory(): void
    {
        $locator = self::service(MailTemplateLocator::class);

        // base.html.twig exists in templates/, one level up, and must stay
        // unreachable however the name is written.
        self::assertFalse($locator->exists('../base'));
        self::assertFalse($locator->exists('..%2Fbase'));
        self::assertFalse(MailTemplateLocator::isValidName('../base'));
    }

    #[Test]
    public function anEmailIsSentPerRecipientAndTheNotificationIsMarkedSent(): void
    {
        $notification = $this->notification(['ops@example.com', 'dev@example.com']);

        self::service(EmailNotificationDelivery::class)->deliver($notification, $this->payload());

        $messages = $this->sentEmails();
        self::assertCount(2, $messages);
        self::assertSame(NotificationStatus::SENT, $notification->getStatus());
    }

    #[Test]
    public function theRealTemplateIsRenderedIntoTheBody(): void
    {
        $notification = $this->notification(['ops@example.com']);

        $payload = new EmailNotificationPayload(
            'noreply@example.com',
            'Order shipped',
            '<p>Tracking below.</p>',
            'example',
            [],
            ['customerName' => 'Ada'],
        );

        self::service(EmailNotificationDelivery::class)->deliver($notification, $payload);

        $body = $this->sentEmails()[0]->getHtmlBody();
        self::assertStringContainsString('<h1 style="font-size: 20px;">Order shipped</h1>', $body);
        self::assertStringContainsString('<p>Tracking below.</p>', $body);
        self::assertStringContainsString('Ada', $body);
    }

    #[Test]
    public function anInlineImageIsEmbeddedAndReferencedByContentId(): void
    {
        $notification = $this->notification(['ops@example.com']);

        $payload = new EmailNotificationPayload(
            'noreply@example.com',
            'Welcome',
            '<p>hi</p><img src="cid:logo">',
            null,
            [new EmailAttachment('logo.png', 'image/png', 'png-bytes', 'logo')],
        );

        self::service(EmailNotificationDelivery::class)->deliver($notification, $payload);

        $rendered = $this->sentEmails()[0]->toString();
        self::assertStringContainsString('Content-Disposition: inline', $rendered);
        self::assertStringNotContainsString('src="cid:logo"', $rendered);
    }

    #[Test]
    public function aPartialFailureMarksOnlyTheFailedRecipientAndRaises(): void
    {
        $notification = $this->notification(['ops@example.com', 'broken@example.com']);

        $delivery = $this->deliveryWithMailerFailingFor(['broken@example.com']);

        try {
            $delivery->deliver($notification, $this->payload());
            self::fail('Expected the failed recipient to raise.');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('failed for 1 of 2 recipients', $e->getMessage());
        }

        self::assertSame(NotificationStatus::FAILED, $notification->getStatus());

        $statuses = [];
        foreach ($notification->getRecipients() as $recipient) {
            $statuses[$recipient->getRecipient()] = $recipient->getStatus();
        }
        self::assertSame(NotificationStatus::SENT, $statuses['ops@example.com']);
        self::assertSame(NotificationStatus::FAILED, $statuses['broken@example.com']);
    }

    #[Test]
    public function aFailedRecipientIsPersistedSoARetryDoesNotResend(): void
    {
        $notification = $this->notification(['ops@example.com', 'broken@example.com']);

        try {
            $this->deliveryWithMailerFailingFor(['broken@example.com'])
                ->deliver($notification, $this->payload());
        } catch (RuntimeException) {
            // asserted in the test above
        }

        // Reload from the database: the retry reads it back, and must see the
        // already delivered recipient as sent rather than pending.
        self::entityManager()->clear();
        $reloaded = self::entityManager()->find(Notification::class, $notification->getId());

        $statuses = [];
        foreach ($reloaded->getRecipients() as $recipient) {
            $statuses[$recipient->getRecipient()] = $recipient->getStatus();
        }
        self::assertSame(NotificationStatus::SENT, $statuses['ops@example.com']);
        self::assertSame(NotificationStatus::FAILED, $statuses['broken@example.com']);
    }

    #[Test]
    public function aRetryOnlyContactsRecipientsThatHaveNotBeenSentTo(): void
    {
        $notification = $this->notification(['already@example.com', 'pending@example.com']);
        foreach ($notification->getRecipients() as $recipient) {
            if ($recipient->getRecipient() === 'already@example.com') {
                $recipient->markProcessing()->markSent();
            }
        }
        self::entityManager()->flush();

        self::service(EmailNotificationDelivery::class)->deliver($notification, $this->payload());

        $addressed = array_map(
            static fn (Email $email): string => $email->getTo()[0]->getAddress(),
            $this->sentEmails(),
        );
        self::assertSame(['pending@example.com'], $addressed);
    }

    #[Test]
    public function everyFailedRecipientIsLoggedWithItsCause(): void
    {
        $notification = $this->notification(['broken@example.com']);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(
                self::stringContains('Email notification delivery failed'),
                self::callback(static fn (array $context): bool => $context['recipient'] === 'broken@example.com'
                    && $context['exception'] instanceof RuntimeException),
            );

        try {
            $this->deliveryWithMailerFailingFor(['broken@example.com'], $logger)
                ->deliver($notification, $this->payload());
        } catch (RuntimeException) {
            // expected
        }
    }

    #[Test]
    public function aBrokenTemplateFailsBeforeAnyRecipientIsContacted(): void
    {
        $notification = $this->notification(['ops@example.com', 'dev@example.com']);

        // The fixture exists but references a variable the payload does not
        // supply, and strict_variables is on in the test environment, so this
        // is a render failure rather than a missing template.
        $payload = new EmailNotificationPayload(
            'noreply@example.com',
            'Subject',
            'body',
            'strict-check',
            [],
        );

        $this->expectException(RuntimeException::class);

        try {
            self::service(EmailNotificationDelivery::class)->deliver($notification, $payload);
        } finally {
            self::assertSame([], $this->sentEmails(), 'no recipient may be contacted');
        }
    }

    /**
     * @param list<string> $failing
     */
    private function deliveryWithMailerFailingFor(
        array $failing,
        ?LoggerInterface $logger = null,
    ): EmailNotificationDelivery {
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willReturnCallback(
            static function (RawMessage $message) use ($failing): void {
                if (in_array($message->getTo()[0]->getAddress(), $failing, true)) {
                    throw new RuntimeException('smtp is unreachable');
                }
            },
        );

        // Real entity manager and real template locator; only the mailer is
        // replaced, because there is no transport that fails on demand.
        return new EmailNotificationDelivery(
            self::entityManager(),
            $mailer,
            $logger ?? $this->createStub(LoggerInterface::class),
            self::service(MailTemplateLocator::class),
        );
    }

    /**
     * @param list<string> $recipients
     */
    private function notification(array $recipients): Notification
    {
        $owner = $this->createUser('alice');
        $notification = new Notification()
            ->assignCreatedBy($owner)
            ->recordCreatedAt(new \DateTimeImmutable())
            ->markQueued();

        foreach ($recipients as $recipient) {
            $notification->addRecipient($recipient);
        }

        self::entityManager()->persist($notification);
        self::entityManager()->flush();

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
     * The mailer dispatches two events per message, one when it hands it to
     * the bus and one when the transport takes it, so the queued half has to
     * be dropped or every email is counted twice.
     *
     * @return list<Email>
     */
    private function sentEmails(): array
    {
        return array_values(array_map(
            static fn (MessageEvent $event): Email => $event->getMessage(),
            array_filter(
                self::getMailerEvents(),
                static fn (MessageEvent $event): bool => !$event->isQueued(),
            ),
        ));
    }
}
