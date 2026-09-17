<?php

declare(strict_types=1);

namespace Nofi\Validator;

use Nofi\Dto\AttachmentDto;
use Nofi\Notification\Email\AttachmentLimits;
use Override;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Both the attachment count and the total decoded size are configurable, so
 * neither can live in a compile-time Assert\Count attribute; this reads the
 * ceilings from AttachmentLimits instead.
 */
final class AttachmentLimitsRespectedValidator extends ConstraintValidator
{
    public function __construct(private readonly AttachmentLimits $limits)
    {
    }

    #[Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof AttachmentLimitsRespected) {
            throw new UnexpectedTypeException($constraint, AttachmentLimitsRespected::class);
        }

        if (!is_array($value)) {
            return;
        }

        if (count($value) > $this->limits->maxAttachments) {
            $this->context->buildViolation($constraint->countMessage)
                ->setParameter("{{ limit }}", (string) $this->limits->maxAttachments)
                ->addViolation();
        }

        $total = 0;
        foreach ($value as $attachment) {
            if ($attachment instanceof AttachmentDto) {
                $total += $attachment->decodedSize();
            }
        }

        if ($total > $this->limits->maxTotalAttachmentBytes) {
            $this->context->buildViolation($constraint->sizeMessage)
                ->setParameter("{{ limit }}", (string) $this->limits->maxTotalAttachmentBytes)
                ->setParameter("{{ actual }}", (string) $total)
                ->addViolation();
        }
    }
}
