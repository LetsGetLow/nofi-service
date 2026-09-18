<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Dto;

use DateTimeImmutable;
use LogicException;
use Nofi\Dto\AttachmentDto;
use Nofi\Dto\NotificationRequestMapper;
use Nofi\Dto\SendNotificationDto;
use Nofi\Notification\Email\AttachmentStorage;
use Nofi\Notification\Email\EmailNotificationPayload;
use Nofi\Notification\NotificationChannel;
use Nofi\Notification\Push\PushNotificationPayload;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationRequestMapperTest extends TestCase
{
    #[Test]
    public function mappingCapturesEmailInputWithoutRetainingTheMutableDto(): void
    {
        $attachment = new AttachmentDto();
        $attachment->filename = 'invoice.pdf';
        $attachment->content = base64_encode("\x00\xffpdf");
        $dto = new SendNotificationDto();
        $dto->channel = NotificationChannel::EMAIL;
        $dto->sender = 'sender@example.com';
        $dto->subject = 'Invoice';
        $dto->message = 'Original body';
        $dto->recipients = [3 => 'alice@example.com'];
        $dto->attachments = [$attachment];
        $dto->data = ['customer' => ['name' => 'Alice']];
        $schedule = $dto->scheduledAt = new DateTimeImmutable('+1 hour');

        $storage = new AttachmentStorage(sys_get_temp_dir());
        $request = $this->mapper($storage)->map($dto);
        $dto->message = 'Changed body';
        $dto->channel = NotificationChannel::PUSH;
        $dto->recipients[] = 'bob@example.com';
        $dto->data['customer']['name'] = 'Bob';
        $dto->scheduledAt = null;
        $attachment->content = base64_encode('changed');

        self::assertInstanceOf(EmailNotificationPayload::class, $request->payload);
        self::assertSame(NotificationChannel::EMAIL, $request->payload->channel());
        self::assertSame('Original body', $request->payload->message);
        self::assertSame(['alice@example.com'], $request->targets);
        self::assertSame(['customer' => ['name' => 'Alice']], $request->payload->data);
        self::assertSame(
            "\x00\xffpdf",
            file_get_contents($storage->absolutePath($request->payload->attachments[0]->path)),
        );
        // Normalised to UTC, not the same object, but the same real instant.
        self::assertNotSame($schedule, $request->scheduledAt);
        self::assertSame($schedule->getTimestamp(), $request->scheduledAt->getTimestamp());
        self::assertSame('UTC', $request->scheduledAt->getTimezone()->getName());
    }

    #[Test]
    public function scheduledAtIsNormalisedToUtcRegardlessOfTheOffsetSent(): void
    {
        $dto = new SendNotificationDto();
        $dto->channel = NotificationChannel::EMAIL;
        $dto->sender = 'sender@example.com';
        $dto->subject = 'Invoice';
        $dto->message = 'Body';
        $dto->recipients = ['alice@example.com'];
        // German summer time: 11:09 CEST is 09:09 UTC, not the same
        // wall-clock digits Doctrine would otherwise store verbatim.
        $dto->scheduledAt = new DateTimeImmutable('2026-09-18T11:09:00+02:00');

        $request = $this->mapper()->map($dto);

        self::assertSame('2026-09-18T09:09:00+00:00', $request->scheduledAt->format('c'));
    }

    #[Test]
    public function mappingPushCombinesTokensAndPrefixedTopicsInOrder(): void
    {
        $dto = new SendNotificationDto();
        $dto->channel = NotificationChannel::PUSH;
        $dto->title = 'Title';
        $dto->message = 'Body';
        $dto->tokens = [4 => 'device-token-1'];
        $dto->topics = [7 => 'news', 9 => 'updates'];

        $request = $this->mapper()->map($dto);

        self::assertInstanceOf(PushNotificationPayload::class, $request->payload);
        self::assertSame(NotificationChannel::PUSH, $request->payload->channel());
        self::assertSame(['device-token-1', '/topics/news', '/topics/updates'], $request->targets);
        self::assertNull($request->scheduledAt);
    }

    #[Test]
    public function aMissingChannelCannotBecomeAnEmailByDefault(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A notification cannot be sent without a channel.');

        $this->mapper()->map(new SendNotificationDto());
    }

    private function mapper(?AttachmentStorage $storage = null): NotificationRequestMapper
    {
        return new NotificationRequestMapper($storage ?? new AttachmentStorage(sys_get_temp_dir()));
    }
}
