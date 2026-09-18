<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Notification\Email;

use Nofi\Notification\Email\AttachmentStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

#[TestDox("AttachmentStorage")]
final class AttachmentStorageTest extends TestCase
{
    private string $shareDir;
    private AttachmentStorage $storage;

    protected function setUp(): void
    {
        $this->shareDir = sys_get_temp_dir() . '/nofi-attachment-storage-test-' . bin2hex(random_bytes(8));
        $this->storage = new AttachmentStorage($this->shareDir);
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->shareDir);
    }

    #[Test]
    public function writeReturnsAPathRelativeToTheShareDirectory(): void
    {
        $path = $this->storage->write('pdf-bytes');

        self::assertStringStartsNotWith($this->shareDir, $path);
        self::assertSame('pdf-bytes', file_get_contents($this->storage->absolutePath($path)));
    }

    #[Test]
    public function absolutePathResolvesAgainstTheShareDirectory(): void
    {
        self::assertSame($this->shareDir . '/attachments/x', $this->storage->absolutePath('attachments/x'));
    }

    #[Test]
    public function twoWritesDoNotCollide(): void
    {
        $first = $this->storage->write('first');
        $second = $this->storage->write('second');

        self::assertNotSame($first, $second);
        self::assertSame('first', file_get_contents($this->storage->absolutePath($first)));
        self::assertSame('second', file_get_contents($this->storage->absolutePath($second)));
    }

    #[Test]
    public function deleteRemovesTheFile(): void
    {
        $path = $this->storage->write('pdf-bytes');

        $this->storage->delete($path);

        self::assertFileDoesNotExist($this->storage->absolutePath($path));
    }

    #[Test]
    public function deletingAnAlreadyMissingPathDoesNotThrow(): void
    {
        $this->storage->delete('attachments/never-written');

        self::assertTrue(true, 'no exception');
    }
}
