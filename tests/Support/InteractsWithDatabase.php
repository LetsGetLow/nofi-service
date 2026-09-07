<?php

declare(strict_types=1);

namespace Nofi\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Nofi\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Builds the schema from the entity metadata rather than running the
 * migrations, so the tests do not depend on PostgreSQL specific DDL and can
 * run against the SQLite database configured in .env.test.
 */
trait InteractsWithDatabase
{
    abstract protected static function testContainer(): ContainerInterface;

    protected function resetDatabase(): void
    {
        $entityManager = self::entityManager();
        $tool = new SchemaTool($entityManager);

        // dropDatabase() rather than dropSchema($metadata): the latter only
        // drops what introspection matches against the metadata, which left
        // tables behind and made the next createSchema fail with "table
        // already exists".
        $tool->dropDatabase();
        $tool->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
    }

    protected static function entityManager(): EntityManagerInterface
    {
        return static::testContainer()->get(EntityManagerInterface::class);
    }

    /**
     * @param list<string> $roles
     */
    protected function createUser(
        string $username,
        array $roles = ["ROLE_USER"],
        string $password = "correct-horse",
    ): User {
        $user = new User()
            ->setUsername($username)
            ->setRoles($roles);

        $hasher = static::testContainer()->get(UserPasswordHasherInterface::class);
        $user->setPassword($hasher->hashPassword($user, $password));

        $entityManager = self::entityManager();
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }
}
