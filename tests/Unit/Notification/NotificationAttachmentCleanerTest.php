<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Notification;

use Nofi\Entity\Notification;
use Nofi\Notification\Email\AttachmentStorage;
use Nofi\Notification\NotificationAttachmentCleaner;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/** AttachmentStorage is final and cannot be doubled, so these use a real one over a temp dir. */
#[TestDox("NotificationAttachmentCleaner")]
final class NotificationAttachmentCleanerTest extends TestCase
{
    private string $shareDir;
    private AttachmentStorage $storage;

    protected function setUp(): void
    {
        $this->shareDir = sys_get_temp_dir() . '/nofi-attachment-cleaner-test-' . bin2hex(random_bytes(8));
        $this->storage = new AttachmentStorage($this->shareDir);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->shareDir);
    }

    #[Test]
    public function itDeletesEveryAttachmentPathInThePayload(): void
    {
        $first = $this->storage->write('a');
        $second = $this->storage->write('b');
        self::assertFileExists($this->storage->absolutePath($first));
        self::assertFileExists($this->storage->absolutePath($second));

        $notification = new Notification()->assignPayload([
            'attachments' => [
                ['path' => $first],
                ['path' => $second],
            ],
        ]);

        new NotificationAttachmentCleaner($this->storage)->cleanUpFor($notification);

        self::assertFileDoesNotExist($this->storage->absolutePath($first));
        self::assertFileDoesNotExist($this->storage->absolutePath($second));
    }

    #[Test]
    public function itIsANoOpWithoutAnAttachmentsKey(): void
    {
        $notification = new Notification()->assignPayload(['sender' => 'noreply@example.com']);

        new NotificationAttachmentCleaner($this->storage)->cleanUpFor($notification);

        self::assertTrue(true, 'no exception');
    }

    #[Test]
    public function itIsANoOpWithAnEmptyAttachmentsList(): void
    {
        $notification = new Notification()->assignPayload(['attachments' => []]);

        new NotificationAttachmentCleaner($this->storage)->cleanUpFor($notification);

        self::assertTrue(true, 'no exception');
    }

    #[Test]
    public function itSkipsAnAttachmentEntryWithNoPath(): void
    {
        $notification = new Notification()->assignPayload([
            'attachments' => [['filename' => 'logo.png', 'path' => null]],
        ]);

        new NotificationAttachmentCleaner($this->storage)->cleanUpFor($notification);

        self::assertTrue(true, 'no exception');
    }
}
