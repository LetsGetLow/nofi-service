<?php

declare(strict_types=1);

namespace Nofi\Notification\Email;

use Nofi\Notification\NotificationPayload;
use Nofi\Notification\NotificationChannel;
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
    ) {
    }

    #[Override]
    public function channel(): NotificationChannel
    {
        return NotificationChannel::EMAIL;
    }

    #[Override]
    public function toPayloadData(): array
    {
        return [
            "sender" => $this->sender,
            "subject" => $this->subject,
            "message" => $this->message,
            "template" => $this->template,
            // Content lives on disk (AttachmentStorage), not here — this is
            // metadata, plus the path (relative to AttachmentStorage's share
            // directory) so a cancel or delete can clean the file up without
            // having to deserialise a queued message.
            "attachments" => array_map(
                static fn (EmailAttachment $attachment): array => [
                    "filename" => $attachment->filename,
                    "contentType" => $attachment->contentType,
                    "contentId" => $attachment->contentId,
                    "size" => $attachment->size,
                    "path" => $attachment->path,
                ],
                $this->attachments,
            ),
            "data" => $this->data,
        ];
    }
}
