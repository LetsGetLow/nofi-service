<?php

declare(strict_types=1);

namespace Nofi\Notification;

use Nofi\Dto\SendNotificationDto;
use InvalidArgumentException;
use Override;

final readonly class EmailNotificationPayload implements NotificationPayload
{
    /**
     * @param list<EmailAttachment>  $attachments
     * @param array<string, mixed>   $data
     */
    public function __construct(
        public string $sender,
        public string $subject,
        public string $message,
        public ?string $template,
        public array $attachments = [],
        public array $data = [],
    ) {}

    public static function fromDto(SendNotificationDto $dto): self
    {
        if ($dto->sender === null || $dto->subject === null || $dto->message === null) {
            throw new InvalidArgumentException("Email payload is incomplete.");
        }

        return new self(
            $dto->sender,
            $dto->subject,
            $dto->message,
            $dto->template,
            array_map(EmailAttachment::fromDto(...), array_values($dto->attachments)),
            $dto->data,
        );
    }

    #[Override]
    public function toPayloadData(): array
    {
        return [
            "sender" => $this->sender,
            "subject" => $this->subject,
            "message" => $this->message,
            "template" => $this->template,
            // Only metadata: the content already travels in the queued
            // message, and persisting it again would duplicate every
            // attachment in the notification table.
            "attachments" => array_map(
                static fn (EmailAttachment $attachment): array => [
                    "filename" => $attachment->filename,
                    "contentType" => $attachment->contentType,
                    "contentId" => $attachment->contentId,
                    "size" => $attachment->size(),
                ],
                $this->attachments,
            ),
            "data" => $this->data,
        ];
    }
}
