<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Validator;

use Nofi\Dto\AttachmentDto;
use Nofi\Notification\Email\AttachmentLimits;
use Nofi\Validator\AttachmentLimitsRespected;
use Nofi\Validator\AttachmentLimitsRespectedValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

#[TestDox("AttachmentLimitsRespectedValidator")]
final class AttachmentLimitsRespectedValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): AttachmentLimitsRespectedValidator
    {
        return new AttachmentLimitsRespectedValidator(
            new AttachmentLimits(
                maxAttachments: 2,
                maxTotalAttachmentBytes: 10,
                allowedInlineContentTypes: ["image/png"],
            ),
        );
    }

    private function attachmentOfSize(int $bytes): AttachmentDto
    {
        $attachment = new AttachmentDto();
        $attachment->content = base64_encode(str_repeat("x", $bytes));

        return $attachment;
    }

    #[Test]
    public function withinBothLimitsPasses(): void
    {
        $this->validator->validate([$this->attachmentOfSize(5)], new AttachmentLimitsRespected());

        $this->assertNoViolation();
    }

    #[Test]
    public function tooManyAttachmentsIsReported(): void
    {
        $this->validator->validate(
            [$this->attachmentOfSize(1), $this->attachmentOfSize(1), $this->attachmentOfSize(1)],
            new AttachmentLimitsRespected(),
        );

        $this->buildViolation("At most {{ limit }} attachments are allowed")
            ->setParameter("{{ limit }}", "2")
            ->assertRaised();
    }

    #[Test]
    public function tooManyBytesIsReported(): void
    {
        $this->validator->validate([$this->attachmentOfSize(11)], new AttachmentLimitsRespected());

        $this->buildViolation("Attachments must not exceed {{ limit }} bytes in total, got {{ actual }}")
            ->setParameter("{{ limit }}", "10")
            ->setParameter("{{ actual }}", "11")
            ->assertRaised();
    }

    #[Test]
    public function bothLimitsCanBeViolatedAtOnce(): void
    {
        $this->validator->validate(
            [$this->attachmentOfSize(6), $this->attachmentOfSize(6), $this->attachmentOfSize(6)],
            new AttachmentLimitsRespected(),
        );

        $this->buildViolation("At most {{ limit }} attachments are allowed")
            ->setParameter("{{ limit }}", "2")
            ->buildNextViolation("Attachments must not exceed {{ limit }} bytes in total, got {{ actual }}")
            ->setParameter("{{ limit }}", "10")
            ->setParameter("{{ actual }}", "18")
            ->assertRaised();
    }

    #[Test]
    public function aNonArrayIsIgnored(): void
    {
        $this->validator->validate(null, new AttachmentLimitsRespected());

        $this->assertNoViolation();
    }

    #[Test]
    public function theWrongConstraintIsRejected(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate([], new NotBlank());
    }
}
