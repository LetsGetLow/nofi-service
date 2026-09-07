<?php

declare(strict_types=1);

namespace Nofi\Tests\Application;

use Nofi\Entity\Notification;
use Nofi\Notification\NotificationStatus;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mailer\Event\MessageEvent;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Cancelling stops a send that has not gone out yet and keeps it as the record
 * of that decision, where deleting would remove the trace.
 *
 * The queued message cannot be withdrawn from the transport, so the last test
 * here is the one that matters: it consumes the queue the way a worker does
 * and asserts that nothing was delivered.
 */
#[TestDox("Cancelling a notification")]
final class CancelNotificationTest extends ApiTestCase
{
    use MailerAssertionsTrait;

    private string $token;
    private string $id;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->tokenFor($this->createUser('alice'));
        $this->id = $this->send();
    }

    #[Test]
    public function aQueuedSendIsCancelledWithEveryRecipientItWouldHaveReached(): void
    {
        $this->request('POST', $this->id . '/cancel', $this->token);

        $this->assertResponseStatus(Response::HTTP_OK);
        self::assertSame('cancelled', $this->jsonResponse()['status']);

        // A cancelled notification whose targets still read "queued" would
        // describe a state that no longer exists.
        self::assertSame(
            ['cancelled', 'cancelled'],
            array_column($this->jsonResponse()['recipients'], 'status'),
        );
        self::assertSame(NotificationStatus::CANCELLED, $this->stored()->getStatus());
    }

    #[Test]
    public function nothingIsDeliveredForACancelledSend(): void
    {
        $this->request('POST', $this->id . '/cancel', $this->token);

        // The message is still in the transport: a delayed one cannot be
        // withdrawn, so the cancellation has to take effect here, when a
        // worker picks it up.
        $this->consumeQueue();

        self::assertSame([], $this->sentEmails(), 'a cancelled send must reach nobody');
        self::assertSame(NotificationStatus::CANCELLED, $this->stored()->getStatus());
    }

    #[Test]
    public function theSkippedMessageIsAcknowledgedRatherThanFailed(): void
    {
        $this->request('POST', $this->id . '/cancel', $this->token);

        // Raising here would retry the message five times and then park it in
        // the failed transport, for a decision that was deliberate.
        self::assertNull($this->consumeQueue(), 'skipping must not raise');
    }

    #[Test]
    public function aSendThatAlreadyWentOutCannotBeCancelled(): void
    {
        $notification = $this->stored();
        $notification->markProcessing()->markSent();
        self::entityManager()->flush();
        self::entityManager()->clear();

        $this->request('POST', $this->id . '/cancel', $this->token);

        $this->assertResponseStatus(Response::HTTP_CONFLICT);
        self::assertStringContainsString('cannot be cancelled', $this->jsonResponse()['detail']);
        self::assertSame(NotificationStatus::SENT, $this->stored()->getStatus());
    }

    #[Test]
    public function cancellingTwiceIsRefusedTheSecondTime(): void
    {
        $this->request('POST', $this->id . '/cancel', $this->token);
        $this->assertResponseStatus(Response::HTTP_OK);

        $this->request('POST', $this->id . '/cancel', $this->token);
        $this->assertResponseStatus(Response::HTTP_CONFLICT);
    }

    #[Test]
    public function anotherUsersNotificationCannotBeCancelled(): void
    {
        $bob = $this->createUser('bob');

        $this->request('POST', $this->id . '/cancel', $this->tokenFor($bob));

        $this->assertResponseStatus(Response::HTTP_NOT_FOUND);
        self::assertSame(NotificationStatus::QUEUED, $this->stored()->getStatus());
    }

    #[Test]
    public function cancellingRequiresAToken(): void
    {
        $this->request('POST', $this->id . '/cancel');

        $this->assertResponseStatus(Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Consumes the async transport the way a worker does and returns whatever
     * a handler raised, so a skipped message can be told from a failed one.
     */
    private function consumeQueue(): ?\Throwable
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $bus = self::getContainer()->get(MessageBusInterface::class);
        $raised = null;

        foreach ($transport->get() as $envelope) {
            try {
                $bus->dispatch($envelope->with(new ReceivedStamp('async')));
            } catch (\Throwable $e) {
                $raised = $e;
            }

            $transport->ack($envelope);
        }

        return $raised;
    }

    /**
     * @return list<\Symfony\Component\Mime\Email>
     */
    private function sentEmails(): array
    {
        return array_values(array_map(
            static fn (MessageEvent $event): object => $event->getMessage(),
            array_filter(
                self::getMailerEvents(),
                static fn (MessageEvent $event): bool => !$event->isQueued(),
            ),
        ));
    }

    private function send(): string
    {
        $this->request('POST', '/api/v1/notifications/send', $this->token, [
            'channel' => 'email',
            'sender' => 'noreply@example.com',
            'subject' => 'Deployment finished',
            'message' => '<p>Build 42 is live</p>',
            'recipients' => ['ops@example.com', 'dev@example.com'],
            'scheduledAt' => '2027-06-01T10:00:00+00:00',
        ]);
        $this->assertResponseStatus(Response::HTTP_ACCEPTED);

        return $this->jsonResponse()['@id'];
    }

    private function stored(): Notification
    {
        self::entityManager()->clear();
        $id = basename($this->id);

        return self::entityManager()->getRepository(Notification::class)->find($id);
    }
}
