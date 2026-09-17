<?php

declare(strict_types=1);

namespace Nofi\Validator;

use Nofi\Dto\AttachmentDto;
use Nofi\Notification\Email\AttachmentLimits;
use Override;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

use function base64_decode;
use function getimagesizefromstring;
use function in_array;
use function strtolower;

/**
 * The declared contentType is only a claim, so the bytes decide. Symfony
 * embeds whatever it is handed, and an unrenderable format reaches the
 * recipient as a broken image with nothing logged anywhere. The allowlist is
 * configurable, so it is read from AttachmentLimits instead of a class
 * constant on AttachmentDto.
 */
final class EmbeddableAttachmentFormatValidator extends ConstraintValidator
{
    public function __construct(private readonly AttachmentLimits $limits)
    {
    }

    #[Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof EmbeddableAttachmentFormat) {
            throw new UnexpectedTypeException($constraint, EmbeddableAttachmentFormat::class);
        }

        if (!$value instanceof AttachmentDto) {
            return;
        }

        if ($value->contentId === null || $value->content === null) {
            return;
        }

        $decoded = base64_decode($value->content, true);
        if ($decoded === false) {
            // Reported separately by AttachmentDto::validateContentIsBase64().
            return;
        }

        // getimagesizefromstring() returns false for anything it cannot read,
        // and subscripting that was an "array offset on bool" warning the @
        // was quietly swallowing. The guard says the same thing out loud.
        $image = @getimagesizefromstring($decoded);
        $detected = is_array($image) ? ($image["mime"] ?? null) : null;

        if ($detected === null) {
            $this->reject("the file is not a readable image");

            return;
        }

        if (!in_array(strtolower($detected), $this->limits->allowedInlineContentTypes, true)) {
            $this->reject(
                sprintf(
                    "%s cannot be embedded, only %s can",
                    $detected,
                    implode(", ", $this->limits->allowedInlineContentTypes),
                ),
            );

            return;
        }

        $declared = strtolower((string) $value->contentType);
        if ($declared !== "" && $declared !== strtolower($detected)) {
            $this->reject(sprintf("contentType says %s but the file is %s", $declared, $detected));
        }
    }

    private function reject(string $reason): void
    {
        $this->context->buildViolation(
            'This file format cannot be used as an embedded ContentID: {{ reason }}. '
            . 'Omit contentId to send it as a regular attachment instead.',
        )
            ->setParameter("{{ reason }}", $reason)
            ->atPath("contentId")
            ->addViolation();
    }
}
