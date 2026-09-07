<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Dto;

use DateTimeImmutable;
use Nofi\Dto\AttachmentDto;
use Nofi\Dto\SendNotificationDto;
use Nofi\Notification\NotificationChannel;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Nofi\Notification\MailTemplateLocator;
use Nofi\Validator\MailTemplateExists;
use Nofi\Validator\MailTemplateExistsValidator;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\ConstraintValidatorFactory;
use Symfony\Component\Validator\ConstraintValidatorFactoryInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[TestDox("SendNotificationDto validation")]
final class SendNotificationDtoTest extends TestCase
{
    private const string PNG_BYTES = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    /** A real 1x1 BMP: a valid image, but not one mail clients render inline. */
    private const string BMP_BYTES = 'Qk05AAAAAAAAADYAAAAoAAAAAQAAAAEAAAABABgAAAAAAAMAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA==';
    private const string GIF_BYTES = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        // MailTemplateExists needs the locator, so the factory has to supply
        // it; everything else falls through to the default.
        $locator = new MailTemplateLocator(
            new Environment(new ArrayLoader(['@mail/example.html.twig' => 'x'])),
        );

        $this->validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->setConstraintValidatorFactory(
                new class ($locator) implements ConstraintValidatorFactoryInterface {
                    private ConstraintValidatorFactory $default;

                    public function __construct(private MailTemplateLocator $locator)
                    {
                        $this->default = new ConstraintValidatorFactory();
                    }

                    public function getInstance(Constraint $constraint): ConstraintValidatorInterface
                    {
                        return $constraint instanceof MailTemplateExists
                            ? new MailTemplateExistsValidator($this->locator)
                            : $this->default->getInstance($constraint);
                    }
                },
            )
            ->getValidator();
    }

    #[Test]
    public function pushNotificationsDoNotRequireASubject(): void
    {
        self::assertSame([], $this->violations($this->pushDto()));
    }

    #[Test]
    public function pushNotificationsRequireATitle(): void
    {
        $dto = $this->pushDto();
        $dto->title = null;

        self::assertSame(
            ['title' => 'Title is required for push notifications'],
            $this->violations($dto),
        );
    }

    #[Test]
    public function emailNotificationsRequireASubject(): void
    {
        $dto = $this->emailDto();
        $dto->subject = null;

        self::assertSame(
            ['subject' => 'Subject is required for email notifications'],
            $this->violations($dto),
        );
    }

    #[Test]
    public function emailNotificationsRequireASender(): void
    {
        $dto = $this->emailDto();
        $dto->sender = null;

        self::assertSame(
            ['sender' => 'Sender is required for email notifications'],
            $this->violations($dto),
        );
    }

    #[Test]
    public function emailRecipientsMustBeEmailAddresses(): void
    {
        $dto = $this->emailDto();
        $dto->recipients = ['not-an-address'];

        self::assertSame(
            ['recipients[0]' => 'Each recipient must be a valid email address for email notifications'],
            $this->violations($dto),
        );
    }

    #[Test]
    public function aScheduledDateIsAccepted(): void
    {
        $dto = $this->emailDto();
        $dto->scheduledAt = new DateTimeImmutable('2026-12-01 10:00:00');

        self::assertSame([], $this->violations($dto));
    }

    #[Test]
    public function recipientsAreRequired(): void
    {
        $dto = $this->emailDto();
        $dto->recipients = [];

        self::assertArrayHasKey('recipients', $this->violations($dto));
    }

    #[Test]
    public function aPushNeedsEitherATokenOrATopic(): void
    {
        $dto = $this->pushDto();
        $dto->tokens = [];

        self::assertSame(
            ['tokens' => 'At least 1 token or topic is required'],
            $this->violations($dto),
        );

        $dto->topics = ['test_notif_all'];
        self::assertSame([], $this->violations($dto));
    }

    #[Test]
    public function aTopicNameIsCheckedAgainstWhatFcmAccepts(): void
    {
        $dto = $this->pushDto();
        $dto->topics = ['not a topic name'];

        self::assertSame(
            ['topics[0]' => 'Topic "not a topic name" is malformed: only letters, digits, '
                . 'hyphens, underscores, dots, tildes and percent signs are allowed'],
            $this->violations($dto),
        );
    }

    #[Test]
    public function aTopicWrittenIntoTokensIsPointedAtTheTopicsField(): void
    {
        $dto = $this->pushDto();
        $dto->tokens = ['/topics/test_notif_all'];

        self::assertSame(
            ['tokens[0]' => 'Use the "topics" field for "test_notif_all" instead of '
                . 'writing /topics/ into "tokens"'],
            $this->violations($dto),
        );
    }

    #[Test]
    public function eachChannelRefusesTheOtherOnesTargetField(): void
    {
        $push = $this->pushDto();
        $push->recipients = ['ops@example.com'];

        self::assertSame(
            ['recipients' => 'Push notifications address devices by "tokens" '
                . 'and audiences by "topics", not "recipients"'],
            $this->violations($push),
        );

        $email = $this->emailDto();
        $email->tokens = ['device-token-1'];

        self::assertSame(
            ['tokens' => 'Email notifications address "recipients", not "tokens"'],
            $this->violations($email),
        );
    }

    #[Test]
    public function anEmailCannotAddressTopics(): void
    {
        $dto = $this->emailDto();
        $dto->topics = ['test_notif_all'];

        self::assertSame(
            ['topics' => 'Email notifications cannot use topics'],
            $this->violations($dto),
        );
    }

    #[Test]
    public function everyTopicBecomesAPrefixedDeliveryTargetAfterTheRecipients(): void
    {
        $dto = $this->pushDto();
        $dto->tokens = ['device-token-1'];
        $dto->topics = ['test_notif_all', 'test_notif_android_device'];

        // The prefix is what the delivery recognises a topic by, and it is
        // added here rather than being asked of the caller.
        self::assertSame(
            [
                'device-token-1',
                '/topics/test_notif_all',
                '/topics/test_notif_android_device',
            ],
            $dto->deliveryTargets(),
        );
    }

    #[Test]
    public function aChannelIsRequired(): void
    {
        $dto = $this->emailDto();
        $dto->channel = null;

        self::assertSame(['channel' => 'Channel is required'], $this->violations($dto));
    }

    private function pushDto(): SendNotificationDto
    {
        $dto = new SendNotificationDto();
        $dto->channel = NotificationChannel::PUSH;
        $dto->title = 'Deployment finished';
        $dto->message = 'Build 42 is live';
        $dto->tokens = ['device-token-1'];

        return $dto;
    }

    private function emailDto(): SendNotificationDto
    {
        $dto = new SendNotificationDto();
        $dto->channel = NotificationChannel::EMAIL;
        $dto->sender = 'noreply@example.com';
        $dto->subject = 'Deployment finished';
        $dto->message = '<h1>Build 42 is live</h1>';
        $dto->recipients = ['ops@example.com'];

        return $dto;
    }

    /**
     * @return array<string, string>
     */
    private function violations(SendNotificationDto $dto): array
    {
        $violations = [];
        foreach ($this->validator->validate($dto) as $violation) {
            $violations[$violation->getPropertyPath()] = (string) $violation->getMessage();
        }

        return $violations;
    }

    #[Test]
    public function aValidAttachmentIsAccepted(): void
    {
        $dto = $this->emailDto();
        $dto->attachments = [$this->attachment()];

        self::assertSame([], $this->violations($dto));
    }

    #[Test]
    public function anAttachmentRequiresAFilename(): void
    {
        $dto = $this->emailDto();
        $attachment = $this->attachment();
        $attachment->filename = null;
        $dto->attachments = [$attachment];

        self::assertArrayHasKey('attachments[0].filename', $this->violations($dto));
    }

    #[Test]
    public function anAttachmentFilenameMayNotContainAPath(): void
    {
        $dto = $this->emailDto();
        $attachment = $this->attachment();
        $attachment->filename = '../../etc/passwd';
        $dto->attachments = [$attachment];

        self::assertArrayHasKey('attachments[0].filename', $this->violations($dto));
    }

    #[Test]
    public function anAttachmentMustBeBase64(): void
    {
        $dto = $this->emailDto();
        $attachment = $this->attachment();
        $attachment->content = 'not base64!!';
        $dto->attachments = [$attachment];

        self::assertSame(
            ['attachments[0].content' => 'Attachment content must be base64 encoded'],
            $this->violations($dto),
        );
    }

    #[Test]
    public function attachmentsAreCappedInTotalSize(): void
    {
        $dto = $this->emailDto();
        $oversized = $this->attachment();
        $oversized->content = base64_encode(str_repeat('x', SendNotificationDto::MAX_TOTAL_ATTACHMENT_BYTES + 1));
        $dto->attachments = [$oversized];

        self::assertArrayHasKey('attachments', $this->violations($dto));
    }

    #[Test]
    public function pushNotificationsMayNotCarryAttachments(): void
    {
        $dto = $this->pushDto();
        $dto->attachments = [$this->attachment()];

        self::assertSame(
            ['attachments' => 'Push notifications cannot carry attachments'],
            $this->violations($dto),
        );
    }

    #[Test]
    public function anInlineAttachmentTakesAContentId(): void
    {
        $dto = $this->emailDto();
        $dto->message = '<p>hi</p><img src="cid:logo">';
        $attachment = $this->attachment();
        $attachment->contentId = 'logo';
        $attachment->contentType = 'image/png';
        $attachment->content = self::PNG_BYTES;
        $dto->attachments = [$attachment];

        self::assertSame([], $this->violations($dto));
    }

    #[Test]
    public function aContentIdMayNotContainWhitespaceOrAngleBrackets(): void
    {
        $dto = $this->emailDto();
        $attachment = $this->attachment();
        $attachment->contentId = '<logo id>';
        $attachment->contentType = 'image/png';
        $attachment->content = self::PNG_BYTES;
        $dto->attachments = [$attachment];

        self::assertArrayHasKey('attachments[0].contentId', $this->violations($dto));
    }

    #[Test]
    public function contentThatIsNotBase64IsReportedOnceNotTwice(): void
    {
        // The embedding check must defer to the base64 check rather than add
        // a second message about the same mistake.
        $dto = $this->emailDto();
        $dto->message = '<img src="cid:logo">';
        $attachment = $this->attachment();
        $attachment->contentType = 'image/png';
        $attachment->contentId = 'logo';
        $attachment->content = 'not base64!!';
        $dto->attachments = [$attachment];

        self::assertSame(
            ['attachments[0].content' => 'Attachment content must be base64 encoded'],
            $this->violations($dto),
        );
    }

    #[Test]
    public function anAttachmentWithNoContentIsReportedOnceNotTwice(): void
    {
        $dto = $this->emailDto();
        $attachment = $this->attachment();
        $attachment->content = null;
        $dto->attachments = [$attachment];

        self::assertSame(
            ['attachments[0].content' => 'Attachment content is required'],
            $this->violations($dto),
        );
    }

    #[Test]
    public function anAttachmentWithNoContentCountsAsNoBytes(): void
    {
        $attachment = new AttachmentDto();

        self::assertSame(0, $attachment->decodedSize());
    }

    #[Test]
    public function aRealImageOfAFormatClientsDoNotRenderCannotBeEmbedded(): void
    {
        // Distinct from a file that is not an image at all: this one decodes
        // fine, it is simply a format that would show as a broken image.
        $dto = $this->emailDto();
        $dto->message = '<img src="cid:pic">';
        $attachment = $this->attachment();
        $attachment->filename = 'pic.bmp';
        $attachment->contentType = 'image/bmp';
        $attachment->contentId = 'pic';
        $attachment->content = self::BMP_BYTES;
        $dto->attachments = [$attachment];

        self::assertStringContainsString(
            'image/bmp cannot be embedded, only image/png, image/jpeg, image/gif can',
            $this->violations($dto)['attachments[0].contentId'] ?? '',
        );
    }

    #[Test]
    public function aBitmapIsStillFineAsARegularAttachment(): void
    {
        $dto = $this->emailDto();
        $attachment = $this->attachment();
        $attachment->filename = 'pic.bmp';
        $attachment->contentType = 'image/bmp';
        $attachment->content = self::BMP_BYTES;
        $dto->attachments = [$attachment];

        self::assertSame([], $this->violations($dto));
    }

    private function attachment(): AttachmentDto
    {
        $attachment = new AttachmentDto();
        $attachment->filename = 'invoice-4711.pdf';
        $attachment->contentType = 'application/pdf';
        $attachment->content = base64_encode('pdf-bytes');

        return $attachment;
    }

    #[Test]
    public function anInlineAttachmentMustBeAReadableImage(): void
    {
        $dto = $this->emailDto();
        $dto->message = '<p>hi</p><img src="cid:scan">';
        $attachment = $this->attachment();
        $attachment->filename = 'scan.tiff';
        $attachment->contentType = 'image/tiff';
        $attachment->contentId = 'scan';
        $dto->attachments = [$attachment];

        self::assertStringContainsString(
            'cannot be used as an embedded ContentID',
            $this->violations($dto)['attachments[0].contentId'] ?? '',
        );
    }

    #[Test]
    public function aMislabelledFileCannotBeEmbedded(): void
    {
        // Claims to be a PNG, actually a GIF. The declared type is only a
        // claim, so the bytes have to decide.
        $dto = $this->emailDto();
        $dto->message = '<p>hi</p><img src="cid:logo">';
        $attachment = $this->attachment();
        $attachment->filename = 'logo.png';
        $attachment->contentType = 'image/png';
        $attachment->contentId = 'logo';
        $attachment->content = self::GIF_BYTES;
        $dto->attachments = [$attachment];

        self::assertStringContainsString(
            'contentType says image/png but the file is image/gif',
            $this->violations($dto)['attachments[0].contentId'] ?? '',
        );
    }

    #[Test]
    public function aRealImageCanBeEmbedded(): void
    {
        $dto = $this->emailDto();
        $dto->message = '<p>hi</p><img src="cid:logo">';
        $attachment = $this->attachment();
        $attachment->filename = 'logo.png';
        $attachment->contentType = 'image/png';
        $attachment->contentId = 'logo';
        $attachment->content = self::PNG_BYTES;
        $dto->attachments = [$attachment];

        self::assertSame([], $this->violations($dto));
    }

    #[Test]
    public function aNonImageCannotBeEmbedded(): void
    {
        $dto = $this->emailDto();
        $dto->message = '<p>hi</p><img src="cid:doc">';
        $attachment = $this->attachment();
        $attachment->contentType = 'image/png';
        $attachment->contentId = 'doc';
        $dto->attachments = [$attachment];

        self::assertStringContainsString(
            'not a readable image',
            $this->violations($dto)['attachments[0].contentId'] ?? '',
        );
    }

    #[Test]
    public function aTiffIsFineAsARegularAttachment(): void
    {
        $dto = $this->emailDto();
        $attachment = $this->attachment();
        $attachment->filename = 'scan.tiff';
        $attachment->contentType = 'image/tiff';
        $dto->attachments = [$attachment];

        self::assertSame([], $this->violations($dto));
    }

    #[Test]
    public function anInlineAttachmentMustBeReferencedInTheMessage(): void
    {
        $dto = $this->emailDto();
        $dto->message = '<p>no image tag here</p>';
        $attachment = $this->attachment();
        $attachment->contentType = 'image/png';
        $attachment->contentId = 'logo';
        $dto->attachments = [$attachment];

        self::assertArrayHasKey('attachments[0].contentId', $this->violations($dto));
    }

    #[Test]
    public function anExistingTemplateIsAccepted(): void
    {
        $dto = $this->emailDto();
        $dto->template = 'example';

        self::assertSame([], $this->violations($dto));
    }

    #[Test]
    public function aMissingTemplateIsRejected(): void
    {
        $dto = $this->emailDto();
        $dto->template = 'does-not-exist';

        self::assertStringContainsString(
            'does not exist',
            $this->violations($dto)['template'] ?? '',
        );
    }

    #[Test]
    public function aTemplateNameCannotEscapeTheDirectory(): void
    {
        $dto = $this->emailDto();
        $dto->template = '../../../config/packages/security';

        self::assertArrayHasKey('template', $this->violations($dto));
    }

    #[Test]
    public function pushNotificationsCannotUseATemplate(): void
    {
        $dto = $this->pushDto();
        $dto->template = 'example';

        self::assertSame(
            ['template' => 'Push notifications cannot use a template'],
            $this->violations($dto),
        );
    }
}
