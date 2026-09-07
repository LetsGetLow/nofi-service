<?php

declare(strict_types=1);

namespace Nofi\Notification;

use Nofi\Dto\AttachmentDto;
use InvalidArgumentException;

use function base64_decode;
use function strlen;

/**
 * An attachment ready to be handed to the mailer: the content is already
 * decoded, so nothing downstream has to know it arrived base64 encoded.
 */
final readonly class EmailAttachment
{
    public const string DEFAULT_CONTENT_TYPE = "application/octet-stream";

    public function __construct(
        public string $filename,
        public string $contentType,
        public string $content,
        public ?string $contentId = null,
    ) {}

    /**
     * Inline parts are embedded in the HTML body and referenced there as
     * "cid:<contentId>", rather than shown as a separate attachment.
     */
    public function isInline(): bool
    {
        return $this->contentId !== null;
    }

    public static function fromDto(AttachmentDto $dto): self
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

        return new self(
            $dto->filename,
            $dto->contentType ?? self::DEFAULT_CONTENT_TYPE,
            $content,
            $dto->contentId,
        );
    }

    public function size(): int
    {
        return strlen($this->content);
    }
}
