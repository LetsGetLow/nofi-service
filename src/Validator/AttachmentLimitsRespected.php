<?php

declare(strict_types=1);

namespace Nofi\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

#[Attribute(Attribute::TARGET_PROPERTY)]
final class AttachmentLimitsRespected extends Constraint
{
    public string $countMessage = "At most {{ limit }} attachments are allowed";

    public string $sizeMessage = "Attachments must not exceed {{ limit }} bytes in total, got {{ actual }}";
}
