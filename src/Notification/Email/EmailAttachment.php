<?php

declare(strict_types=1);

namespace Nofi\Notification\Email;

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
    ) {
    }

    /**
     * Inline parts are embedded in the HTML body and referenced there as
     * "cid:<contentId>", rather than shown as a separate attachment.
     */
    public function isInline(): bool
    {
        return $this->contentId !== null;
    }

    public function size(): int
    {
        return strlen($this->content);
    }
}
