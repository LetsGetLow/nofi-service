<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Notification;

use Nofi\Dto\AttachmentDto;
use Nofi\Dto\SendNotificationDto;
use Nofi\Notification\EmailNotificationPayload;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox("EmailNotificationPayload behavior")]
final class EmailNotificationPayloadTest extends TestCase
{
    #[Test]
    public function fromDtoBuildsAnExplicitPayloadObject(): void
    {
        $dto = new SendNotificationDto();
        $dto->sender = "noreply@example.com";
        $dto->subject = "Hello";
        $dto->message = "Hi there";
        $dto->template = "welcome";
        $attachment = new AttachmentDto();
        $attachment->filename = "terms.pdf";
        $attachment->contentType = "application/pdf";
        $attachment->content = base64_encode("pdf-bytes");
        $dto->attachments = [$attachment];
        $dto->data = ["name" => "Ada"];

        $payload = EmailNotificationPayload::fromDto($dto);

        self::assertInstanceOf(EmailNotificationPayload::class, $payload);
        self::assertSame("noreply@example.com", $payload->sender);
        self::assertSame("Hello", $payload->subject);
        self::assertSame("Hi there", $payload->message);
        self::assertSame("welcome", $payload->template);
        self::assertCount(1, $payload->attachments);
        self::assertSame("terms.pdf", $payload->attachments[0]->filename);
        self::assertSame("application/pdf", $payload->attachments[0]->contentType);
        self::assertSame("pdf-bytes", $payload->attachments[0]->content);
        self::assertFalse($payload->attachments[0]->isInline());
        self::assertSame(["name" => "Ada"], $payload->data);
    }

    #[Test]
    public function fromDtoRejectsIncompletePayloads(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmailNotificationPayload::fromDto(new SendNotificationDto());
    }
}
