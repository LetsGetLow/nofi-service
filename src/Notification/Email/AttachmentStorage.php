<?php

declare(strict_types=1);

namespace Nofi\Notification\Email;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Uid\Uuid;

/**
 * Attachment bytes live on disk rather than inside the queued message, so a
 * failed send does not leave them sitting in the messenger_messages table
 * indefinitely. var/share is shared between the php and messenger-worker
 * containers (compose.yaml mounts the same var_data volume on both), so a
 * path written here is already visible to whichever worker delivers it.
 *
 * write() and delete() deal in paths relative to the share directory, not
 * absolute ones: that relative path is what travels in the queued message
 * and what gets persisted into a notification's metadata, so a later change
 * to APP_SHARE_DIR cannot strand an already-stored or already-queued
 * reference pointing at the old location.
 */
final readonly class AttachmentStorage
{
    private const string SUBDIRECTORY = "attachments";

    public function __construct(
        #[Autowire("%kernel.share_dir%")]
        private string $shareDir,
        private Filesystem $filesystem = new Filesystem(),
    ) {
    }

    /**
     * One file per attachment, named for a fresh id rather than the
     * notification's: the notification id does not exist yet when this
     * runs. Not the caller's filename either — the display name a recipient
     * sees comes from EmailAttachment::$filename, passed separately to
     * attachFromPath()/embedFromPath(), so nothing here depends on client
     * input. A UUID v7 collision is astronomically unlikely but checked for
     * anyway, rather than silently overwriting an existing file.
     * Returns the path relative to the share directory.
     */
    public function write(string $content): string
    {
        do {
            $relativePath = self::SUBDIRECTORY . "/" . Uuid::v7()->toRfc4122();
        } while ($this->filesystem->exists($this->absolutePath($relativePath)));

        $this->filesystem->dumpFile($this->absolutePath($relativePath), $content);

        return $relativePath;
    }

    public function absolutePath(string $relativePath): string
    {
        return $this->shareDir . "/" . $relativePath;
    }

    /** Idempotent: a path already gone (or never written) is not an error. */
    public function delete(string $relativePath): void
    {
        $this->filesystem->remove($this->absolutePath($relativePath));
    }
}
