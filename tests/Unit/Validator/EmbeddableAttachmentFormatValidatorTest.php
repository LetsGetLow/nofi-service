<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Validator;

use Nofi\Dto\AttachmentDto;
use Nofi\Notification\Email\AttachmentLimits;
use Nofi\Validator\EmbeddableAttachmentFormat;
use Nofi\Validator\EmbeddableAttachmentFormatValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

#[TestDox("EmbeddableAttachmentFormatValidator")]
final class EmbeddableAttachmentFormatValidatorTest extends ConstraintValidatorTestCase
{
    /** A real 1x1 PNG. */
    private const string PNG_BYTES = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** A real 1x1 BMP: a valid image, but not one mail clients render inline. */
    private const string BMP_BYTES = 'Qk05AAAAAAAAADYAAAAoAAAAAQAAAAEAAAABABgAAAAAAAMAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA==';

    protected function createValidator(): EmbeddableAttachmentFormatValidator
    {
        return new EmbeddableAttachmentFormatValidator(
            new AttachmentLimits(
                maxAttachments: 10,
                maxTotalAttachmentBytes: 1024,
                allowedInlineContentTypes: ["image/png", "image/jpeg", "image/gif"],
            ),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        // This is a class-level constraint, so atPath("contentId") should
        // report exactly that path, not one nested under the base test
        // case's default "property.path".
        $this->setPropertyPath("");
    }

    private function attachment(): AttachmentDto
    {
        $attachment = new AttachmentDto();
        $attachment->filename = "logo.png";
        $attachment->content = base64_encode("pdf-bytes");

        return $attachment;
    }

    #[Test]
    public function notInlineIsIgnored(): void
    {
        $attachment = $this->attachment();
        $attachment->contentId = null;

        $this->validator->validate($attachment, new EmbeddableAttachmentFormat());

        $this->assertNoViolation();
    }

    #[Test]
    public function anAllowedImageTypePasses(): void
    {
        $attachment = $this->attachment();
        $attachment->contentId = "logo";
        $attachment->contentType = "image/png";
        $attachment->content = self::PNG_BYTES;

        $this->validator->validate($attachment, new EmbeddableAttachmentFormat());

        $this->assertNoViolation();
    }

    #[Test]
    public function aFormatOutsideTheAllowlistIsReported(): void
    {
        $attachment = $this->attachment();
        $attachment->contentId = "pic";
        $attachment->contentType = "image/bmp";
        $attachment->content = self::BMP_BYTES;

        $this->validator->validate($attachment, new EmbeddableAttachmentFormat());

        $this->buildViolation(
            'This file format cannot be used as an embedded ContentID: {{ reason }}. '
            . 'Omit contentId to send it as a regular attachment instead.',
        )
            ->setParameter(
                "{{ reason }}",
                "image/bmp cannot be embedded, only image/png, image/jpeg, image/gif can",
            )
            ->atPath("contentId")
            ->assertRaised();
    }

    #[Test]
    public function anUnreadableFileIsReported(): void
    {
        $attachment = $this->attachment();
        $attachment->contentId = "doc";
        $attachment->content = base64_encode("not an image");

        $this->validator->validate($attachment, new EmbeddableAttachmentFormat());

        $this->buildViolation(
            'This file format cannot be used as an embedded ContentID: {{ reason }}. '
            . 'Omit contentId to send it as a regular attachment instead.',
        )
            ->setParameter("{{ reason }}", "the file is not a readable image")
            ->atPath("contentId")
            ->assertRaised();
    }

    #[Test]
    public function aMislabelledFileIsReported(): void
    {
        $attachment = $this->attachment();
        $attachment->contentId = "logo";
        $attachment->contentType = "image/jpeg";
        $attachment->content = self::PNG_BYTES;

        $this->validator->validate($attachment, new EmbeddableAttachmentFormat());

        $this->buildViolation(
            'This file format cannot be used as an embedded ContentID: {{ reason }}. '
            . 'Omit contentId to send it as a regular attachment instead.',
        )
            ->setParameter("{{ reason }}", "contentType says image/jpeg but the file is image/png")
            ->atPath("contentId")
            ->assertRaised();
    }

    #[Test]
    public function contentThatIsNotBase64IsLeftToTheBase64Check(): void
    {
        $attachment = $this->attachment();
        $attachment->contentId = "logo";
        $attachment->content = "not base64!!";

        $this->validator->validate($attachment, new EmbeddableAttachmentFormat());

        $this->assertNoViolation();
    }

    #[Test]
    public function aNonAttachmentIsIgnored(): void
    {
        $this->validator->validate(null, new EmbeddableAttachmentFormat());

        $this->assertNoViolation();
    }

    #[Test]
    public function theWrongConstraintIsRejected(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate($this->attachment(), new NotBlank());
    }
}
