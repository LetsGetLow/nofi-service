<?php

declare(strict_types=1);

namespace Nofi\Tests\Application;

use Nofi\Entity\Notification;
use Nofi\Notification\NotificationStatus;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Deleting removes a send that has not started, where cancelling would keep the
 * record. The rule is the same one cancelling enforces — a worker that is
 * already delivering cannot be stopped — and these tests exist because the two
 * operations used to disagree about it.
 *
 * The processor asked isFinal() while cancelling asked isWaiting(). PROCESSING
 * is neither, so a send in flight was refused a cancellation and granted a
 * deletion, and the handler removed the row while the worker was mid-delivery.
 * Both now ask isWithdrawable().
 */
#[TestDox("Deleting a notification")]
final class DeleteNotificationTest extends ApiTestCase
{
    private string $token;
    private string $id;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->tokenFor($this->createUser('admin', ['ROLE_ADMIN']));
        $this->id = $this->send();
    }

    #[Test]
    public function aQueuedSendIsDeleted(): void
    {
        $this->request('DELETE', $this->id, $this->token);

        $this->assertResponseStatus(Response::HTTP_NO_CONTENT);

        $this->consumeQueue();
        self::assertNull($this->stored(), 'the record must be gone once the queue is consumed');
    }

    #[Test]
    public function aSendInFlightCannotBeDeleted(): void
    {
        $this->markProcessing();

        $this->request('DELETE', $this->id, $this->token);

        $this->assertResponseStatus(Response::HTTP_CONFLICT);
        self::assertStringContainsString('cannot be deleted', $this->jsonResponse()['detail']);
        self::assertNotNull($this->stored(), 'a send being delivered must survive the request');
    }

    #[Test]
    public function aSendThatAlreadyWentOutCannotBeDeleted(): void
    {
        $notification = $this->stored();
        $notification->markProcessing()->markSent();
        self::entityManager()->flush();
        self::entityManager()->clear();

        $this->request('DELETE', $this->id, $this->token);

        $this->assertResponseStatus(Response::HTTP_CONFLICT);
        self::assertNotNull($this->stored());
    }

    /**
     * The gap the processor alone cannot close: deleting is asynchronous, so a
     * worker can pick the send up between the request being accepted and the
     * message being consumed. The handler has to ask the question again.
     */
    #[Test]
    public function aSendThatStartsAfterTheRequestIsNotRemoved(): void
    {
        $this->request('DELETE', $this->id, $this->token);
        $this->assertResponseStatus(Response::HTTP_NO_CONTENT);

        $this->markProcessing();
        $this->consumeQueue();

        $stored = $this->stored();
        self::assertNotNull($stored, 'the handler must refuse a send that started meanwhile');
        self::assertSame(NotificationStatus::PROCESSING, $stored->getStatus());
    }

    #[Test]
    public function deletingRequiresAnAdmin(): void
    {
        $this->request('DELETE', $this->id, $this->tokenFor($this->createUser('alice')));

        $this->assertResponseStatus(Response::HTTP_FORBIDDEN);
        self::assertNotNull($this->stored());
    }

    private function markProcessing(): void
    {
        $this->stored()->markProcessing();
        self::entityManager()->flush();
        self::entityManager()->clear();
    }

    private function consumeQueue(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        $bus = self::getContainer()->get(MessageBusInterface::class);

        foreach ($transport->get() as $envelope) {
            $bus->dispatch($envelope->with(new ReceivedStamp('async')));
            $transport->ack($envelope);
        }
    }

    private function send(): string
    {
        $this->request('POST', '/api/v1/notifications/send', $this->token, [
            'channel' => 'email',
            'sender' => 'noreply@example.com',
            'subject' => 'Deployment finished',
            'message' => '<p>Build 42 is live</p>',
            'recipients' => ['ops@example.com'],
            'scheduledAt' => '2027-06-01T10:00:00+00:00',
        ]);
        $this->assertResponseStatus(Response::HTTP_ACCEPTED);

        return $this->jsonResponse()['@id'];
    }

    private function stored(): ?Notification
    {
        self::entityManager()->clear();

        return self::entityManager()
            ->getRepository(Notification::class)
            ->find(basename($this->id));
    }
}
