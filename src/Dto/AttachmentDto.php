<?php

declare(strict_types=1);

namespace Nofi\Dto;

use Nofi\Validator\EmbeddableAttachmentFormat;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function base64_decode;

#[EmbeddableAttachmentFormat]
final class AttachmentDto
{
    /** Long enough for any sensible filename, short enough to keep headers sane. */
    public const int MAX_FILENAME_LENGTH = 255;

    public const int MAX_CONTENT_ID_LENGTH = 255;

    /**
     * Path separators and control characters are rejected because the value
     * ends up as the filename a recipient sees and saves.
     */
    #[Assert\NotBlank(message: "Attachment filename is required")]
    #[Assert\Length(
        max: self::MAX_FILENAME_LENGTH,
        maxMessage: "Attachment filename must be {{ limit }} characters or fewer",
    )]
    #[Assert\Regex(
        pattern: '#^[^/\\\\\x00-\x1f]+$#',
        message: "Attachment filename must not contain path separators or control characters",
    )]
    public ?string $filename = null;

    #[Assert\Regex(
        pattern: '#^[a-zA-Z0-9!\#$&^_.+-]+/[a-zA-Z0-9!\#$&^_.+-]+$#',
        message: "Attachment contentType must be a media type such as application/pdf",
    )]
    public ?string $contentType = null;

    /**
     * Set to embed the file in the HTML body instead of attaching it, and
     * reference it there as <img src="cid:THIS-VALUE">. Symfony renames
     * inline parts after their content id, so filename is not shown for them.
     */
    #[Assert\Length(
        max: self::MAX_CONTENT_ID_LENGTH,
        maxMessage: "Attachment contentId must be {{ limit }} characters or fewer",
    )]
    #[Assert\Regex(
        pattern: '#^[^<>\s\x00-\x1f]+$#',
        message: "Attachment contentId must not contain whitespace, angle brackets or control characters",
    )]
    public ?string $contentId = null;

    /**
     * Base64 encoded file contents.
     */
    #[Assert\NotBlank(message: "Attachment content is required")]
    public ?string $content = null;

    #[Assert\Callback]
    public function validateContentIsBase64(ExecutionContextInterface $context): void
    {
        // An empty value is already reported by NotBlank.
        if ($this->content === null || $this->content === "") {
            return;
        }

        if (base64_decode($this->content, true) === false) {
            $context->buildViolation("Attachment content must be base64 encoded")
                ->atPath("content")
                ->addViolation();
        }
    }

    public function decodedSize(): int
    {
        if ($this->content === null) {
            return 0;
        }

        $decoded = base64_decode($this->content, true);

        return $decoded === false ? 0 : strlen($decoded);
    }
}
