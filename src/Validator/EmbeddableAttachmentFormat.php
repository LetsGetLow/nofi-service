<?php

declare(strict_types=1);

namespace Nofi\Validator;

use Attribute;
use Override;
use Symfony\Component\Validator\Constraint;

#[Attribute(Attribute::TARGET_CLASS)]
final class EmbeddableAttachmentFormat extends Constraint
{
    #[Override]
    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
