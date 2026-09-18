<?php

declare(strict_types=1);

namespace Nofi\Tests\Application;

use Nofi\Entity\Notification;
use Nofi\Notification\Email\AttachmentStorage;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

#[TestDox("Deleting a notification")]
final class DeleteNotificationTest extends ApiTestCase
{
    use MailerAssertionsTrait;

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

        self::assertNull($this->stored(), 'the record must be gone when DELETE returns');
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
    public function aCancelledSendCanBeDeleted(): void
    {
        $this->markCancelled();

        $this->request('DELETE', $this->id, $this->token);

        $this->assertResponseStatus(Response::HTTP_NO_CONTENT);
        self::assertNull($this->stored(), 'a cancelled send was never delivered and may be removed');
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

    #[Test]
    public function aDeletedSendCannotBeDeliveredByTheWorker(): void
    {
        $this->request('DELETE', $this->id, $this->token);
        $this->assertResponseStatus(Response::HTTP_NO_CONTENT);

        $this->consumeQueue();

        self::assertEmailCount(0);
        self::assertNull($this->stored());
    }

    #[Test]
    public function deletingRemovesTheAttachmentFile(): void
    {
        $id = $this->sendWithAttachment();
        $path = $this->attachmentPath($id);
        self::assertFileExists($path);

        $this->request('DELETE', $id, $this->token);

        $this->assertResponseStatus(Response::HTTP_NO_CONTENT);
        self::assertFileDoesNotExist($path);
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

    private function markCancelled(): void
    {
        $this->stored()->markCancelled();
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

    private function sendWithAttachment(): string
    {
        $this->request('POST', '/api/v1/notifications/send', $this->token, [
            'channel' => 'email',
            'sender' => 'noreply@example.com',
            'subject' => 'Invoice',
            'message' => '<p>See attached</p>',
            'recipients' => ['ops@example.com'],
            'scheduledAt' => '2027-06-01T10:00:00+00:00',
            'attachments' => [
                ['filename' => 'invoice.pdf', 'contentType' => 'application/pdf', 'content' => base64_encode('pdf-bytes')],
            ],
        ]);
        $this->assertResponseStatus(Response::HTTP_ACCEPTED);

        return $this->jsonResponse()['@id'];
    }

    private function attachmentPath(string $id): string
    {
        self::entityManager()->clear();
        $notification = self::entityManager()->getRepository(Notification::class)->find(basename($id));
        $relativePath = $notification->getPayload()['attachments'][0]['path'];

        return self::getContainer()->get(AttachmentStorage::class)->absolutePath($relativePath);
    }
}
