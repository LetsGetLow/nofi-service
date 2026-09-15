<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Dto;

use Nofi\Dto\NotificationRequestMapper;
use Nofi\Dto\AttachmentDto;
use Nofi\Dto\SendNotificationDto;
use Nofi\Notification\Email\EmailNotificationPayload;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox("EmailNotificationPayload mapping")]
final class EmailNotificationPayloadMappingTest extends TestCase
{
    #[Test]
    public function mappingBuildsAnExplicitPayloadObject(): void
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

        $payload = new NotificationRequestMapper()->emailPayload($dto);

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
    public function mappingRejectsIncompletePayloads(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new NotificationRequestMapper()->emailPayload(new SendNotificationDto());
    }
}
