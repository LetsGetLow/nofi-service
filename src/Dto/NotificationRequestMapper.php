<?php

declare(strict_types=1);

namespace Nofi\Dto;

use InvalidArgumentException;
use LogicException;
use Nofi\Notification\Email\AttachmentStorage;
use Nofi\Notification\Email\EmailAttachment;
use Nofi\Notification\Email\EmailNotificationPayload;
use Nofi\Notification\NotificationChannel;
use Nofi\Notification\NotificationRequest;
use Nofi\Notification\Push\PushNotificationPayload;
use Nofi\Notification\Push\PushTopic;

/** Converts validated API input into the values used by the application. */
final readonly class NotificationRequestMapper
{
    public function __construct(private AttachmentStorage $attachmentStorage)
    {
    }

    public function map(SendNotificationDto $dto): NotificationRequest
    {
        $channel = $dto->channel ?? throw new LogicException("A notification cannot be sent without a channel.");
        $payload = match ($channel) {
            NotificationChannel::EMAIL => $this->emailPayload($dto),
            NotificationChannel::PUSH => $this->pushPayload($dto),
        };

        return new NotificationRequest($payload, [
            ...array_values($dto->recipients),
            ...array_values($dto->tokens),
            ...array_map(
                static fn (string $topic): string => PushTopic::PREFIX . $topic,
                array_values($dto->topics),
            ),
        ], $dto->scheduledAt);
    }

    public function emailPayload(SendNotificationDto $dto): EmailNotificationPayload
    {
        if ($dto->sender === null || $dto->subject === null || $dto->message === null) {
            throw new InvalidArgumentException("Email payload is incomplete.");
        }

        return new EmailNotificationPayload(
            $dto->sender,
            $dto->subject,
            $dto->message,
            $dto->template,
            array_map($this->attachment(...), array_values($dto->attachments)),
            $dto->data,
        );
    }

    public function pushPayload(SendNotificationDto $dto): PushNotificationPayload
    {
        if ($dto->title === null || $dto->message === null) {
            throw new InvalidArgumentException("Push payload is incomplete.");
        }

        return new PushNotificationPayload(
            $dto->title,
            $dto->message,
            $dto->icon,
            $dto->data,
        );
    }

    public function attachment(AttachmentDto $dto): EmailAttachment
    {
        if ($dto->filename === null || $dto->content === null) {
            throw new InvalidArgumentException("Attachment is incomplete.");
        }

        $content = base64_decode($dto->content, true);
        if ($content === false) {
            throw new InvalidArgumentException(
                sprintf('Attachment "%s" is not valid base64.', $dto->filename),
            );
        }

        $path = $this->attachmentStorage->write($content);

        return new EmailAttachment(
            $dto->filename,
            $dto->contentType ?? EmailAttachment::DEFAULT_CONTENT_TYPE,
            $path,
            strlen($content),
            $dto->contentId,
        );
    }
}
