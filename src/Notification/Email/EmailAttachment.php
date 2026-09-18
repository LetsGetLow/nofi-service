<?php

declare(strict_types=1);

namespace Nofi\Notification\Email;

/**
 * An attachment ready to be handed to the mailer: content lives on disk,
 * already decoded, so nothing downstream has to know it arrived base64
 * encoded or read it more than once.
 */
final readonly class EmailAttachment
{
    public const string DEFAULT_CONTENT_TYPE = "application/octet-stream";

    public function __construct(
        public string $filename,
        public string $contentType,
        /** Relative to AttachmentStorage's share directory; resolve with absolutePath(). */
        public string $path,
        public int $size,
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
}
