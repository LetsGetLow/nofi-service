<?php

declare(strict_types=1);

namespace Nofi\Dto;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

use function base64_decode;
use function getimagesizefromstring;
use function in_array;
use function strtolower;

final class AttachmentDto
{
    /**
     * Formats mail clients reliably render inline. Symfony embeds anything it
     * is given without complaint, so a TIFF or a PDF would be delivered as a
     * broken image with no error anywhere; refuse those here instead. Widen
     * this list deliberately if a recipient base is known to cope.
     */
    public const array INLINE_CONTENT_TYPES = ["image/png", "image/jpeg", "image/gif"];

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

    /**
     * The declared contentType is only a claim, so the bytes decide. Symfony
     * embeds whatever it is handed, and an unrenderable format reaches the
     * recipient as a broken image with nothing logged anywhere.
     */
    #[Assert\Callback]
    public function validateFormatCanBeEmbedded(ExecutionContextInterface $context): void
    {
        if ($this->contentId === null || $this->content === null) {
            return;
        }

        $decoded = base64_decode($this->content, true);
        if ($decoded === false) {
            // Reported separately by validateContentIsBase64().
            return;
        }

        // getimagesizefromstring() returns false for anything it cannot read,
        // and subscripting that was an "array offset on bool" warning the @
        // was quietly swallowing. The guard says the same thing out loud.
        $image = @getimagesizefromstring($decoded);
        $detected = is_array($image) ? ($image["mime"] ?? null) : null;

        if ($detected === null) {
            $this->rejectEmbedding($context, "the file is not a readable image");

            return;
        }

        if (!in_array(strtolower($detected), self::INLINE_CONTENT_TYPES, true)) {
            $this->rejectEmbedding(
                $context,
                sprintf("%s cannot be embedded, only %s can", $detected, implode(", ", self::INLINE_CONTENT_TYPES)),
            );

            return;
        }

        $declared = strtolower((string) $this->contentType);
        if ($declared !== "" && $declared !== strtolower($detected)) {
            $this->rejectEmbedding(
                $context,
                sprintf("contentType says %s but the file is %s", $declared, $detected),
            );
        }
    }

    private function rejectEmbedding(ExecutionContextInterface $context, string $reason): void
    {
        $context->buildViolation(
            'This file format cannot be used as an embedded ContentID: {{ reason }}. '
            . 'Omit contentId to send it as a regular attachment instead.',
        )
            ->setParameter("{{ reason }}", $reason)
            ->atPath("contentId")
            ->addViolation();
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
