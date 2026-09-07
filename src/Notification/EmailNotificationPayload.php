<?php

declare(strict_types=1);

namespace Nofi\Notification;

use Nofi\Dto\SendNotificationDto;
use InvalidArgumentException;

final readonly class EmailNotificationPayload
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
}
