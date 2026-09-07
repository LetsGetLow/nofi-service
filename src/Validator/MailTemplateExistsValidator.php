<?php

declare(strict_types=1);

namespace Nofi\Validator;

use Nofi\Notification\MailTemplateLocator;
use Override;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Checks the template at request time. Without this a typo would be accepted
 * with 202 and only fail later inside a worker, where the caller never sees it.
 */
final class MailTemplateExistsValidator extends ConstraintValidator
{
    public function __construct(private readonly MailTemplateLocator $locator) {}

    #[Override]
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof MailTemplateExists) {
            throw new UnexpectedTypeException($constraint, MailTemplateExists::class);
        }

        // An empty template is valid; the name format is checked separately.
        if ($value === null || $value === "" || !is_string($value)) {
            return;
        }

        if (!MailTemplateLocator::isValidName($value) || $this->locator->exists($value)) {
            return;
        }

        $this->context->buildViolation($constraint->message)
            ->setParameter("{{ template }}", $value)
            ->addViolation();
    }
}
