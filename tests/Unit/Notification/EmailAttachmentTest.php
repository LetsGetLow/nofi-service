<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Notification;

use Nofi\Dto\AttachmentDto;
use Nofi\Notification\EmailAttachment;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox("EmailAttachment")]
final class EmailAttachmentTest extends TestCase
{
    #[Test]
    public function fromDtoDecodesTheContent(): void
    {
        $attachment = EmailAttachment::fromDto($this->dto());

        self::assertSame('invoice.pdf', $attachment->filename);
        self::assertSame('application/pdf', $attachment->contentType);
        self::assertSame('pdf-bytes', $attachment->content);
        self::assertSame(strlen('pdf-bytes'), $attachment->size());
        self::assertFalse($attachment->isInline());
    }

    #[Test]
    public function contentTypeFallsBackToOctetStream(): void
    {
        $dto = $this->dto();
        $dto->contentType = null;

        self::assertSame(
            EmailAttachment::DEFAULT_CONTENT_TYPE,
            EmailAttachment::fromDto($dto)->contentType,
        );
    }

    #[Test]
    public function aContentIdMakesItInline(): void
    {
        $dto = $this->dto();
        $dto->contentId = 'logo';

        self::assertTrue(EmailAttachment::fromDto($dto)->isInline());
    }

    #[Test]
    public function fromDtoRejectsAMissingFilename(): void
    {
        $dto = $this->dto();
        $dto->filename = null;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Attachment is incomplete.');
        EmailAttachment::fromDto($dto);
    }

    #[Test]
    public function fromDtoRejectsMissingContent(): void
    {
        $dto = $this->dto();
        $dto->content = null;

        $this->expectException(InvalidArgumentException::class);
        EmailAttachment::fromDto($dto);
    }

    #[Test]
    public function fromDtoRejectsContentThatIsNotBase64(): void
    {
        $dto = $this->dto();
        $dto->content = 'not base64!!';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"invoice.pdf" is not valid base64');
        EmailAttachment::fromDto($dto);
    }

    private function dto(): AttachmentDto
    {
        $dto = new AttachmentDto();
        $dto->filename = 'invoice.pdf';
        $dto->contentType = 'application/pdf';
        $dto->content = base64_encode('pdf-bytes');

        return $dto;
    }
}
