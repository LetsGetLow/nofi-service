<?php

declare(strict_types=1);

namespace Nofi\Tests\Application;

use Nofi\Entity\Notification;
use Nofi\Notification\NotificationChannel;
use Nofi\Notification\NotificationStatus;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

/**
 * The send endpoint end to end: what it accepts, what it refuses, and what it
 * leaves behind in the database.
 */
#[TestDox("Sending notifications")]
final class SendNotificationTest extends ApiTestCase
{
    private const string SEND = '/api/v1/notifications/send';
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $token;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->tokenFor($this->createUser('alice'));
    }

    #[Test]
    public function anEmailIsAcceptedAndRecorded(): void
    {
        $this->send($this->email());
        $this->assertResponseStatus(Response::HTTP_ACCEPTED);

        $notification = $this->onlyNotification();
        self::assertSame(NotificationChannel::EMAIL, $notification->getChannel());
        self::assertSame(NotificationStatus::QUEUED, $notification->getStatus());
        self::assertSame('alice', $notification->getCreatedBy()->getUserIdentifier());
        self::assertCount(1, $notification->getRecipients());
        self::assertSame('Deployment finished', $notification->getPayload()['subject']);
    }

    #[Test]
    public function aPushIsAcceptedWithoutASubject(): void
    {
        $this->send([
            'channel' => 'push',
            'title' => 'Deployment finished',
            'message' => 'Build 42 is live',
            'tokens' => ['device-token-1'],
        ]);

        $this->assertResponseStatus(Response::HTTP_ACCEPTED);
        self::assertSame(NotificationChannel::PUSH, $this->onlyNotification()->getChannel());
    }

    #[Test]
    public function aScheduledSendIsAccepted(): void
    {
        $this->send([...$this->email(), 'scheduledAt' => '2026-12-01T10:00:00+00:00']);

        $this->assertResponseStatus(Response::HTTP_ACCEPTED);
        self::assertNotNull($this->onlyNotification()->getScheduledAt());
    }

    #[Test]
    public function anAttachmentIsRecordedWithoutItsContent(): void
    {
        $this->send([...$this->email(), 'attachments' => [
            ['filename' => 'invoice.pdf', 'contentType' => 'application/pdf', 'content' => base64_encode('pdf-bytes')],
        ]]);

        $this->assertResponseStatus(Response::HTTP_ACCEPTED);

        $attachments = $this->onlyNotification()->getPayload()['attachments'];
        self::assertSame('invoice.pdf', $attachments[0]['filename']);
        self::assertSame(strlen('pdf-bytes'), $attachments[0]['size']);
        self::assertStringNotContainsString(
            'pdf-bytes',
            json_encode($this->onlyNotification()->getPayload(), JSON_THROW_ON_ERROR),
        );
    }

    #[Test]
    public function anInlineImageIsAccepted(): void
    {
        $this->send([
            ...$this->email(),
            'message' => '<p>hi</p><img src="cid:logo">',
            'attachments' => [
                ['filename' => 'logo.png', 'contentType' => 'image/png', 'content' => self::PNG, 'contentId' => 'logo'],
            ],
        ]);

        $this->assertResponseStatus(Response::HTTP_ACCEPTED);
    }

    #[Test]
    public function aTemplatedEmailIsAccepted(): void
    {
        $this->send([...$this->email(), 'template' => 'example']);

        $this->assertResponseStatus(Response::HTTP_ACCEPTED);
        self::assertSame('example', $this->onlyNotification()->getPayload()['template']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPayloads')]
    public function invalidRequestsAreRejected(array $payload, string $expectedPath): void
    {
        $this->send($payload);

        $this->assertResponseStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
        self::assertStringContainsString($expectedPath, (string) $this->client->getResponse()->getContent());
        self::assertSame(0, $this->countNotifications());
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): iterable
    {
        $email = [
            'channel' => 'email',
            'sender' => 'noreply@example.com',
            'subject' => 'Deployment finished',
            'message' => '<p>Build 42 is live</p>',
            'recipients' => ['ops@example.com'],
        ];

        yield 'no channel' => [[...$email, 'channel' => null], 'channel'];
        yield 'no recipients' => [[...$email, 'recipients' => []], 'recipients'];
        yield 'recipient is not an address' => [[...$email, 'recipients' => ['nope']], 'recipients'];
        yield 'email without sender' => [[...$email, 'sender' => null], 'sender'];
        yield 'push without title' => [
            ['channel' => 'push', 'message' => 'body', 'tokens' => ['t']],
            'title',
        ];
        yield 'push with attachments' => [
            ['channel' => 'push', 'title' => 't', 'message' => 'm', 'tokens' => ['t'], 'attachments' => [
                ['filename' => 'a.pdf', 'content' => base64_encode('x')],
            ]],
            'attachments',
        ];
        yield 'push with template' => [
            ['channel' => 'push', 'title' => 't', 'message' => 'm', 'tokens' => ['t'], 'template' => 'example'],
            'template',
        ];
        yield 'attachment content is not base64' => [
            [...$email, 'attachments' => [['filename' => 'a.pdf', 'content' => 'not base64!!']]],
            'content',
        ];
        yield 'attachment filename contains a path' => [
            [...$email, 'attachments' => [['filename' => '../../etc/passwd', 'content' => base64_encode('x')]]],
            'filename',
        ];
        yield 'inline attachment is not an image' => [
            [
                ...$email,
                'message' => '<img src="cid:doc">',
                'attachments' => [['filename' => 'a.pdf', 'contentType' => 'image/png', 'content' => base64_encode('%PDF-1.4'), 'contentId' => 'doc']],
            ],
            'contentId',
        ];
        yield 'inline attachment is never referenced' => [
            [
                ...$email,
                'attachments' => [['filename' => 'l.png', 'contentType' => 'image/png', 'content' => self::PNG, 'contentId' => 'logo']],
            ],
            'contentId',
        ];
        yield 'template does not exist' => [[...$email, 'template' => 'nope'], 'template'];
        yield 'template is empty' => [[...$email, 'template' => ''], 'template'];
        yield 'template escapes the directory' => [[...$email, 'template' => '../../config/packages/security'], 'template'];
    }


    /**
     * @param array<string, mixed> $payload
     */
    private function send(array $payload): void
    {
        $this->request('POST', self::SEND, $this->token, $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function email(): array
    {
        return [
            'channel' => 'email',
            'sender' => 'noreply@example.com',
            'subject' => 'Deployment finished',
            'message' => '<p>Build 42 is live</p>',
            'recipients' => ['ops@example.com'],
        ];
    }

    private function onlyNotification(): Notification
    {
        self::entityManager()->clear();
        $all = self::entityManager()->getRepository(Notification::class)->findAll();
        self::assertCount(1, $all);

        return $all[0];
    }

    private function countNotifications(): int
    {
        self::entityManager()->clear();

        return count(self::entityManager()->getRepository(Notification::class)->findAll());
    }
}
