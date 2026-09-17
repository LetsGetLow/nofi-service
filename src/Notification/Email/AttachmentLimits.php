<?php

declare(strict_types=1);

namespace Nofi\Notification\Email;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Attachment ceilings, sourced from the environment so an operator can raise
 * or lower them without a code change. Raising maxTotalAttachmentBytes also
 * means raising PHP_POST_MAX_SIZE, PHP_WORKER_MEMORY_LIMIT and
 * MESSENGER_MEMORY_LIMIT in .env, since attachments travel base64 encoded
 * through the request body and the queued message alike — see the comments
 * there.
 */
final readonly class AttachmentLimits
{
    /**
     * @param list<string> $allowedInlineContentTypes
     */
    public function __construct(
        #[Autowire('%env(int:MAX_ATTACHMENTS)%')]
        public int $maxAttachments,
        #[Autowire('%env(int:MAX_TOTAL_ATTACHMENT_BYTES)%')]
        public int $maxTotalAttachmentBytes,
        #[Autowire('%env(csv:ALLOWED_INLINE_ATTACHMENT_CONTENT_TYPES)%')]
        public array $allowedInlineContentTypes,
    ) {
    }
}
