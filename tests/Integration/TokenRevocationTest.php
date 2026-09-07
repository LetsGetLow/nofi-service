<?php

declare(strict_types=1);

namespace Nofi\Tests\Integration;

use Nofi\Entity\User;
use Nofi\Repository\UserRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;

#[TestDox("Revoking tokens")]
final class TokenRevocationTest extends IntegrationTestCase
{
    #[Test]
    public function theRepositoryIncrementsInTheDatabase(): void
    {
        $user = $this->createUser('alice');

        self::service(UserRepository::class)->revokeTokens($user);

        self::assertSame(2, $this->storedVersion('alice'));
        self::assertSame(2, $user->getTokenVersion(), 'the in-memory copy must agree');
    }

    #[Test]
    public function theRepositoryDoesNotOverwriteAnIncrementItDidNotSee(): void
    {
        $user = $this->createUser('alice');

        // Someone else revokes while this request holds the entity: the row is
        // now at 5, but the loaded copy still says 1.
        self::entityManager()->getConnection()
            ->executeStatement("UPDATE nofi_user SET token_version = 5 WHERE username = 'alice'");

        self::service(UserRepository::class)->revokeTokens($user);

        // 6, not 2. Writing 2 would have brought tokens 2 to 5 back to life.
        self::assertSame(6, $this->storedVersion('alice'));
    }

    #[Test]
    public function theEntityMethodWritesAValueWorkedOutInMemory(): void
    {
        $user = $this->createUser('alice');
        self::entityManager()->getConnection()
            ->executeStatement("UPDATE nofi_user SET token_version = 5 WHERE username = 'alice'");

        $user->revokeTokens();
        self::entityManager()->flush();

        // Documents why the repository method exists: this lowers the counter.
        self::assertSame(2, $this->storedVersion('alice'));
    }

    #[Test]
    public function changingThePasswordMovesTheVersionOn(): void
    {
        $user = $this->createUser('alice', password: 'old-password');
        $before = $user->getTokenVersion();

        $user->setPassword('a-different-hash');

        self::assertSame($before + 1, $user->getTokenVersion());
    }

    #[Test]
    public function settingTheSamePasswordAgainDoesNotRevokeAnything(): void
    {
        $user = $this->createUser('alice');
        $before = $user->getTokenVersion();

        $user->setPassword($user->getPassword());

        self::assertSame($before, $user->getTokenVersion());
    }

    private function storedVersion(string $username): int
    {
        return (int) self::entityManager()->getConnection()
            ->fetchOne('SELECT token_version FROM nofi_user WHERE username = ?', [$username]);
    }
}
