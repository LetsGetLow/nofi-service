<?php

declare(strict_types=1);

namespace Nofi\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class MailTemplateExists extends Constraint
{
    public string $message = 'Template "{{ template }}" does not exist. Templates live in templates/mail and are addressed by their bare filename.';
}
