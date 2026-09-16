<?php

declare(strict_types=1);

namespace Nofi\Tests\Concurrency;

use DateTimeImmutable;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Tools\SchemaTool;
use Nofi\Entity\Notification;
use Nofi\Entity\User;
use Nofi\Notification\NotificationLifecycle;
use Nofi\Tests\Support\PostgresEntityManager;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class NotificationLifecycleTest extends TestCase
{
    private ?EntityManager $entityManager = null;
    private string $dsn;
    private string $schema;

    #[Override]
    protected function setUp(): void
    {
        $dsn = getenv('NOFI_TEST_POSTGRES_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('Set NOFI_TEST_POSTGRES_DSN to run PostgreSQL concurrency tests.');
        }

        $this->dsn = $dsn;
        $this->schema = 'nofi_lock_test_' . bin2hex(random_bytes(8));
        $this->entityManager = PostgresEntityManager::create($dsn, $this->schema);
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement('CREATE SCHEMA ' . $connection->quoteIdentifier($this->schema));
        new SchemaTool($this->entityManager)->createSchema($this->entityManager->getMetadataFactory()->getAllMetadata());
    }

    #[Override]
    protected function tearDown(): void
    {
        if ($this->entityManager !== null) {
            $connection = $this->entityManager->getConnection();
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection->executeStatement('DROP SCHEMA ' . $connection->quoteIdentifier($this->schema) . ' CASCADE');
            $connection->close();
        }
    }

    public static function races(): iterable
    {
        yield 'worker before worker' => ['claimNotificationForDelivery', 'claimNotificationForDelivery', 'conflict', 'processing'];
        yield 'worker before cancellation' => ['claimNotificationForDelivery', 'cancelNotification', 'conflict', 'processing'];
        yield 'worker before deletion' => ['claimNotificationForDelivery', 'deleteNotification', 'conflict', 'processing'];
        yield 'cancellation before worker' => ['cancelNotification', 'claimNotificationForDelivery', 'missing', 'cancelled'];
        yield 'deletion before worker' => ['deleteNotification', 'claimNotificationForDelivery', 'missing', null];
        yield 'deletion before cancellation' => ['deleteNotification', 'cancelNotification', 'missing', null];
    }

    #[Test]
    #[DataProvider('races')]
    public function theFirstLockedOperationWinsDespiteCachedState(
        string $first,
        string $second,
        string $expectedResult,
        ?string $expectedStatus,
    ): void {
        $entityManager = $this->entityManager;
        $connection = $entityManager->getConnection();
        $user = new User()->setUsername('lock-test')->setPassword('unused');
        $notification = new Notification()
            ->assignCreatedBy($user)
            ->recordCreatedAt(new DateTimeImmutable())
            ->markQueued();
        $notification->addRecipient('alice@example.com');
        $entityManager->persist($user);
        $entityManager->persist($notification);
        $entityManager->flush();
        $id = $notification->getId();

        $process = proc_open([
            PHP_BINARY,
            dirname(__DIR__) . '/Support/notification-lock-worker.php',
            $this->schema,
            $id,
            $second,
        ], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [
            'NOFI_TEST_POSTGRES_DSN' => $this->dsn,
        ]);
        self::assertIsResource($process);
        stream_set_timeout($pipes[1], 10);

        try {
            $pid = (int) fgets($pipes[1]);
            self::assertGreaterThan(0, $pid);

            // Hold the first operation's lock until the other connection is
            // verifiably waiting on it. No timing-based guess about the race.
            $connection->beginTransaction();
            new NotificationLifecycle($entityManager)->{$first}($id);
            fwrite($pipes[0], "go\n");
            fflush($pipes[0]);

            $deadline = microtime(true) + 3;
            do {
                $waiting = $connection->fetchOne(
                    'SELECT wait_event_type FROM pg_stat_activity WHERE pid = ?',
                    [$pid],
                );
                if ($waiting === 'Lock') {
                    break;
                }
                usleep(10000);
            } while (microtime(true) < $deadline);

            self::assertSame('Lock', $waiting, 'The second operation must wait for the row lock.');
            $connection->commit();
            self::assertSame($expectedResult, trim((string) fgets($pipes[1])));

            $row = $connection->fetchAssociative(
                'SELECT status, processing_started_at FROM notification WHERE id = ?',
                [$id],
            );
            self::assertSame($expectedStatus, $row === false ? null : $row['status']);
            if ($expectedStatus === 'processing') {
                self::assertNotNull($row['processing_started_at']);
            }

            self::assertSame('', stream_get_contents($pipes[2]));
        } finally {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }
    }
}
