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
