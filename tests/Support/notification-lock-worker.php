<?php

declare(strict_types=1);

use Nofi\Entity\Notification;
use Nofi\Notification\NotificationLifecycle;
use Nofi\Tests\Support\PostgresEntityManager;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

[$script, $schema, $id, $operation] = $argv;
$entityManager = PostgresEntityManager::create((string) getenv('NOFI_TEST_POSTGRES_DSN'), $schema);
// Simulate an API provider that read the entity before the competing operation.
$entityManager->find(Notification::class, $id);
echo $entityManager->getConnection()->fetchOne('SELECT pg_backend_pid()') . "\n";
flush();
fgets(STDIN);

try {
    $result = new NotificationLifecycle($entityManager)->{$operation}($id);
    echo $result instanceof Notification ? $result->getStatus()->value : 'missing';
} catch (DomainException) {
    echo 'conflict';
}
echo "\n";
