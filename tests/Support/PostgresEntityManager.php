<?php

declare(strict_types=1);

namespace Nofi\Tests\Support;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use Doctrine\ORM\ORMSetup;

final class PostgresEntityManager
{
    public static function create(string $dsn, string $schema): EntityManager
    {
        $connection = DriverManager::getConnection(new DsnParser(['postgresql' => 'pdo_pgsql'])->parse($dsn));
        $connection->executeStatement('SET search_path TO ' . $connection->quoteIdentifier($schema));
        $connection->executeStatement("SET lock_timeout = '5s'");
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 2) . '/src/Entity'], true);
        $config->setNamingStrategy(new UnderscoreNamingStrategy(CASE_LOWER));
        $config->enableNativeLazyObjects(true);

        return new EntityManager($connection, $config);
    }
}
