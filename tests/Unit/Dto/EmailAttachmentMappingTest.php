<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Dto;

use Nofi\Dto\NotificationRequestMapper;
use Nofi\Dto\AttachmentDto;
use Nofi\Notification\Email\EmailAttachment;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox("EmailAttachment mapping")]
final class EmailAttachmentMappingTest extends TestCase
{
    #[Test]
    public function mappingDecodesTheContent(): void
    {
        $attachment = new NotificationRequestMapper()->attachment($this->dto());

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
            new NotificationRequestMapper()->attachment($dto)->contentType,
        );
    }

    #[Test]
    public function aContentIdMakesItInline(): void
    {
        $dto = $this->dto();
        $dto->contentId = 'logo';

        self::assertTrue(new NotificationRequestMapper()->attachment($dto)->isInline());
    }

    #[Test]
    public function mappingRejectsAMissingFilename(): void
    {
        $dto = $this->dto();
        $dto->filename = null;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Attachment is incomplete.');
        new NotificationRequestMapper()->attachment($dto);
    }

    #[Test]
    public function mappingRejectsMissingContent(): void
    {
        $dto = $this->dto();
        $dto->content = null;

        $this->expectException(InvalidArgumentException::class);
        new NotificationRequestMapper()->attachment($dto);
    }

    #[Test]
    public function mappingRejectsContentThatIsNotBase64(): void
    {
        $dto = $this->dto();
        $dto->content = 'not base64!!';

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('"invoice.pdf" is not valid base64');
        new NotificationRequestMapper()->attachment($dto);
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
