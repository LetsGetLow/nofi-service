<?php

declare(strict_types=1);

namespace Nofi\Dto;

use DateTimeImmutable;
use Nofi\Dto\AttachmentDto;
use Nofi\Notification\MailTemplateLocator;
use Nofi\Validator\MailTemplateExists;
use Nofi\Notification\NotificationChannel;
use Nofi\Notification\PushTopic;
use ApiPlatform\Metadata\ApiProperty;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

#[Assert\Callback("validateChannelSpecificFields")]
final class SendNotificationDto
{
    /**
     * Attachments travel inside the queued message, so the ceiling keeps a
     * single send from bloating the messenger_messages row.
     */
    public const int MAX_TOTAL_ATTACHMENT_BYTES = 5 * 1024 * 1024;

    public const int MAX_ATTACHMENTS = 10;

    // No Assert\DateTime here: it validates strings, and this property is
    // already a DateTimeImmutable by the time validation runs. A malformed
    // value fails denormalization before it ever reaches the validator.
    #[ApiProperty(
        description: "When to deliver. Left out, the send goes as soon as a worker picks it up. "
            . "The delay is stamped onto the queued message, so it cannot be moved afterwards: "
            . "cancel and send again instead.",
        example: "2026-12-01T10:00:00+00:00",
    )]
    #[SerializedName("scheduledAt")]
    public ?DateTimeImmutable $scheduledAt = null;

    #[ApiProperty(
        description: "Which way to send. The rest of the request depends on it: email uses sender, "
            . "subject, recipients and may carry attachments; push uses title, tokens and topics.",
        example: "email",
    )]
    #[Assert\NotNull(message: "Channel is required")]
    public ?NotificationChannel $channel = null;

    #[ApiProperty(description: "Email only, and required there.", example: "noreply@example.com")]
    #[Assert\Email(message: "Sender must be a valid email address")]
    public ?string $sender = null;

    // Required for email only, enforced in validateChannelSpecificFields().
    #[ApiProperty(description: "Email only, and required there.", example: "Order 4711 shipped")]
    public ?string $subject = null;

    // Required for push only, enforced in validateChannelSpecificFields().
    #[ApiProperty(
        description: "Push only, and required there: the headline the device shows.",
        example: "Order 4711 shipped",
    )]
    public ?string $title = null;

    #[ApiProperty(
        description: "The body. For email this is HTML and becomes the mail itself, or a variable "
            . "inside a template when one is named. For push it is the text under the title.",
        example: "<p>Your parcel is on its way.</p>",
    )]
    #[Assert\NotBlank(message: "Message is required")]
    public ?string $message = null;


    #[ApiProperty(
        description: "Push only: the image the device shows beside the notification.",
        example: "https://example.com/icon.png",
    )]
    #[Assert\Url(message: "Icon must be a valid URL", requireTld: true)]
    public ?string $icon = null;

    /**
     * Bare filename of a template in templates/mail, e.g. "welcome" for
     * templates/mail/welcome.html.twig. Email only.
     */
    #[Assert\NotBlank(
        allowNull: true,
        message: "Template must not be empty. Omit it to send the message as the body",
    )]
    #[ApiProperty(
        description: "Email only. Names a Twig template in templates/mail, addressed by bare "
            . "filename: \"welcome\" resolves to welcome.html.twig. Inside it, every key of data "
            . "is a variable, plus subject and message. Left out, message is the body.",
        example: "welcome",
    )]
    #[Assert\Regex(
        pattern: MailTemplateLocator::NAME_PATTERN,
        message: "Template must be a bare filename using letters, digits, underscores or hyphens",
    )]
    #[MailTemplateExists]
    public ?string $template = null;

    /**
     * @var array<int, AttachmentDto>
     */
    #[ApiProperty(
        description: "Email only. Files sent with the mail, content base64 encoded. Give a contentId "
            . "to embed one in the HTML body and reference it there as <img src=\"cid:THE-CONTENT-ID\">; "
            . "without it the file is a normal attachment. At most 10 files, 5 MB decoded in total. "
            . "Only the metadata is kept afterwards, never the bytes.",
    )]
    #[Assert\Valid]
    #[Assert\Type(type: "array", message: "Attachments must be an array")]
    #[Assert\Count(max: self::MAX_ATTACHMENTS, maxMessage: "At most {{ limit }} attachments are allowed")]
    public array $attachments = [];

    /**
     * @var array<string, mixed>
     */
    #[ApiProperty(
        description: "Free key/value data. For email with a template, every key becomes a Twig "
            . "variable, so a list can be looped into a table. For push it is delivered to the "
            . "device for client-side handling such as deep links and is not shown to the user; "
            . "FCM carries strings only, so any other value is JSON encoded and the client decodes it.",
        example: ["deepLink" => "/orders/4711", "orderId" => 4711],
    )]
    #[Assert\Type(type: "array", message: "Data must be an array")]
    public array $data = [];

    /**
     * Email addresses. Email only — a push addresses devices by token and
     * audiences by topic, which are named for what they are.
     *
     * @var array<int, string>
     */
    #[ApiProperty(example: ["kunde@example.com"])]
    #[Assert\Type(type: "array", message: "Recipients must be an array")]
    #[Assert\All([
        new Assert\NotBlank(message: "Recipient cannot be blank"),
    ])]
    public array $recipients = [];

    /**
     * Device registration tokens, push only, for addressing single devices.
     * Unlike a topic a token reports back per device, so an uninstalled app
     * becomes a failed recipient instead of a silent success.
     *
     * @var array<int, string>
     */
    #[ApiProperty(example: ["fMEr9c1TQ_example_device_token"])]
    #[Assert\Type(type: "array", message: "Tokens must be an array")]
    #[Assert\All([
        new Assert\NotBlank(message: "Token cannot be blank"),
    ])]
    public array $tokens = [];

    /**
     * FCM topic names, push only, given bare: "test_notif_all", not
     * "/topics/test_notif_all". Every device subscribed to the topic receives
     * the message, so this reaches an audience without knowing any token.
     *
     * @var array<int, string>
     */
    #[ApiProperty(example: ["test_notif_all"])]
    #[Assert\Type(type: "array", message: "Topics must be an array")]
    #[Assert\All([
        new Assert\NotBlank(message: "Topic cannot be blank"),
    ])]
    public array $topics = [];

    public function validateChannelSpecificFields(ExecutionContextInterface $context): void
    {
        // Null is the one case handled here: #[Assert\NotNull] on $channel
        // already reports it, and asking the enum would mean asking nothing.
        // Every real channel dispatches through NotificationChannel, so this
        // method does not have to be edited when one is added. It used to
        // carry a "default => null" arm, which meant a new channel was
        // accepted with no field validation whatsoever.
        $this->channel?->validate($this, $context);
    }

    /**
     * Symfony only rewrites cid: references it finds in an <img src> or a
     * background attribute, and silently leaves an unreferenced inline part
     * out of the related body. Either mistake ships a broken email with no
     * error, so catch both here.
     */
    private function validateInlineAttachmentsAreReferenced(ExecutionContextInterface $context): void
    {
        foreach ($this->attachments as $index => $attachment) {
            if (!$attachment instanceof AttachmentDto || $attachment->contentId === null) {
                continue;
            }

            if (!str_contains((string) $this->message, "cid:" . $attachment->contentId)) {
                $context->buildViolation(
                    'Inline attachment {{ cid }} is never referenced in the message. '
                    . 'Add <img src="cid:{{ raw }}"> to the message, or omit contentId '
                    . 'to send it as a regular attachment.',
                )
                    ->setParameter("{{ cid }}", '"' . $attachment->contentId . '"')
                    ->setParameter("{{ raw }}", $attachment->contentId)
                    ->atPath("attachments[" . $index . "].contentId")
                    ->addViolation();
            }
        }
    }

    /**
     * The whole message, attachments included, is serialised into the queue,
     * so the total is capped rather than the size of any single file.
     */
    private function validateAttachmentSize(ExecutionContextInterface $context): void
    {
        $total = 0;
        foreach ($this->attachments as $attachment) {
            if ($attachment instanceof AttachmentDto) {
                $total += $attachment->decodedSize();
            }
        }

        if ($total > self::MAX_TOTAL_ATTACHMENT_BYTES) {
            $context->buildViolation(
                "Attachments must not exceed {{ limit }} bytes in total, got {{ actual }}",
            )
                ->setParameter("{{ limit }}", (string) self::MAX_TOTAL_ATTACHMENT_BYTES)
                ->setParameter("{{ actual }}", (string) $total)
                ->atPath("attachments")
                ->addViolation();
        }
    }

    public function validateEmailFields(ExecutionContextInterface $context): void
    {
        if (empty($this->sender)) {
            $context->buildViolation("Sender is required for email notifications")
                ->atPath("sender")
                ->addViolation();
        }
        if (empty($this->subject)) {
            $context->buildViolation("Subject is required for email notifications")
                ->atPath("subject")
                ->addViolation();
        }

        if ($this->recipients === []) {
            $context->buildViolation("Recipients are required")
                ->atPath("recipients")
                ->addViolation();
        }

        if ($this->tokens !== []) {
            $context->buildViolation('Email notifications address "recipients", not "tokens"')
                ->atPath("tokens")
                ->addViolation();
        }

        if ($this->topics !== []) {
            $context->buildViolation("Email notifications cannot use topics")
                ->atPath("topics")
                ->addViolation();
        }

        
        $this->validateAttachmentSize($context);
        $this->validateInlineAttachmentsAreReferenced($context);

        foreach ($this->recipients as $index => $recipient) {
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $context->buildViolation("Each recipient must be a valid email address for email notifications")
                    ->atPath("recipients[" . $index . "]")
                    ->addViolation();
            }
        }
    }

    public function validatePushFields(ExecutionContextInterface $context): void
    {
        if ($this->attachments !== []) {
            $context->buildViolation("Push notifications cannot carry attachments")
                ->atPath("attachments")
                ->addViolation();
        }

        if ($this->template !== null) {
            $context->buildViolation("Push notifications cannot use a template")
                ->atPath("template")
                ->addViolation();
        }

        if (empty($this->title)) {
            $context->buildViolation("Title is required for push notifications")
                ->atPath("title")
                ->addViolation();
        }

        if ($this->tokens === [] && $this->topics === []) {
            $context->buildViolation("At least 1 token or topic is required")
                ->atPath("tokens")
                ->addViolation();
        }

        if ($this->recipients !== []) {
            $context->buildViolation(
                'Push notifications address devices by "tokens" and audiences by "topics", not "recipients"',
            )
                ->atPath("recipients")
                ->addViolation();
        }

        $this->validateTopicNames($context);
        $this->validateTopicsAreNotWrittenAsTokens($context);
    }

    /**
     * A device token is opaque and cannot be checked here, but a topic can:
     * FCM rejects a malformed name, and without this the request is accepted
     * with 202 and fails later inside a worker where nobody sees it.
     */
    private function validateTopicNames(ExecutionContextInterface $context): void
    {
        foreach ($this->topics as $index => $topic) {
            if (!is_string($topic) || $topic === "") {
                // Reported by the All(NotBlank) on the property.
                continue;
            }

            if (!PushTopic::isValidName($topic)) {
                $context->buildViolation(
                    "Topic {{ topic }} is malformed: only letters, digits, hyphens, "
                    . "underscores, dots, tildes and percent signs are allowed",
                )
                    ->setParameter("{{ topic }}", '"' . $topic . '"')
                    ->atPath("topics[" . $index . "]")
                    ->addViolation();
            }
        }
    }

    /**
     * The prefix is how the application marks a topic internally, so writing
     * it into recipients would work by accident and leave two ways to say the
     * same thing. Point the caller at the field instead.
     */
    private function validateTopicsAreNotWrittenAsTokens(ExecutionContextInterface $context): void
    {
        foreach ($this->tokens as $index => $token) {
            if (!is_string($token) || !PushTopic::isTopic($token)) {
                continue;
            }

            $context->buildViolation(
                'Use the "topics" field for {{ name }} instead of writing '
                . PushTopic::PREFIX . ' into "tokens"',
            )
                ->setParameter("{{ name }}", '"' . PushTopic::nameOf($token) . '"')
                ->atPath("tokens[" . $index . "]")
                ->addViolation();
        }
    }

    /**
     * Every target a delivery has to address, whatever the channel called
     * them: email recipients, device tokens, and each topic in the prefixed
     * form the rest of the application recognises a topic by.
     *
     * @return list<string>
     */
    public function deliveryTargets(): array
    {
        return [
            ...array_values($this->recipients),
            ...array_values($this->tokens),
            ...array_map(
                static fn (string $topic): string => PushTopic::PREFIX . $topic,
                array_values($this->topics),
            ),
        ];
    }
}
