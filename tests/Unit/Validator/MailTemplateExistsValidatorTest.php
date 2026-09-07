<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Validator;

use Nofi\Notification\MailTemplateLocator;
use Nofi\Validator\MailTemplateExists;
use Nofi\Validator\MailTemplateExistsValidator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

#[TestDox("MailTemplateExistsValidator")]
final class MailTemplateExistsValidatorTest extends ConstraintValidatorTestCase
{
    protected function createValidator(): MailTemplateExistsValidator
    {
        return new MailTemplateExistsValidator(
            new MailTemplateLocator(new Environment(new ArrayLoader([
                '@mail/welcome.html.twig' => 'hello',
            ]))),
        );
    }

    #[Test]
    public function anExistingTemplatePasses(): void
    {
        $this->validator->validate('welcome', new MailTemplateExists());

        $this->assertNoViolation();
    }

    #[Test]
    public function aMissingTemplateIsReported(): void
    {
        $this->validator->validate('nope', new MailTemplateExists());

        $this->buildViolation('Template "{{ template }}" does not exist. Templates live in templates/mail and are addressed by their bare filename.')
            ->setParameter('{{ template }}', 'nope')
            ->assertRaised();
    }

    #[Test]
    public function nullIsLeftToTheOptionalityOfTheField(): void
    {
        $this->validator->validate(null, new MailTemplateExists());

        $this->assertNoViolation();
    }

    #[Test]
    public function anInvalidNameIsLeftToTheFormatConstraint(): void
    {
        // Reporting here as well would give the caller two messages for one
        // mistake, so the name pattern owns it.
        $this->validator->validate('../base', new MailTemplateExists());

        $this->assertNoViolation();
    }

    #[Test]
    public function aNonStringIsIgnored(): void
    {
        $this->validator->validate(42, new MailTemplateExists());

        $this->assertNoViolation();
    }

    #[Test]
    public function theWrongConstraintIsRejected(): void
    {
        $this->expectException(UnexpectedTypeException::class);

        $this->validator->validate('welcome', new NotBlank());
    }
}
