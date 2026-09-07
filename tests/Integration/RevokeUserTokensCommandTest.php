<?php

declare(strict_types=1);

namespace Nofi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The operator's way to cut off a leaked token. TokenRevocationTest covers what
 * the increment itself guarantees; this covers that the command reaches it.
 */
#[TestDox("nofi:user:revoke-tokens")]
final class RevokeUserTokensCommandTest extends IntegrationTestCase
{
    #[Test]
    public function itMovesTheTokenVersionOn(): void
    {
        $this->createUser('alice');

        $tester = $this->tester();
        $status = $tester->execute(['username' => 'alice']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertSame(2, $this->storedVersion('alice'));
        self::assertStringContainsString('now 2', $tester->getDisplay());
    }

    #[Test]
    public function itIncrementsWhatTheRowHoldsRatherThanWhatItRead(): void
    {
        $this->createUser('alice');
        self::entityManager()->getConnection()
            ->executeStatement("UPDATE nofi_user SET token_version = 5 WHERE username = 'alice'");

        $this->tester()->execute(['username' => 'alice']);

        // 6, not 2: writing 2 would bring tokens 2 to 5 back to life.
        self::assertSame(6, $this->storedVersion('alice'));
    }

    #[Test]
    public function itLeavesTheOtherUsersAlone(): void
    {
        $this->createUser('alice');
        $this->createUser('bob');

        $this->tester()->execute(['username' => 'alice']);

        self::assertSame(1, $this->storedVersion('bob'));
    }

    #[Test]
    public function itReportsAUsernameThatDoesNotExist(): void
    {
        $tester = $this->tester();
        $status = $tester->execute(['username' => 'nobody']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No user named', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(
            new Application(self::$kernel)->find('nofi:user:revoke-tokens'),
        );
    }

    private function storedVersion(string $username): int
    {
        return (int) self::entityManager()->getConnection()
            ->fetchOne('SELECT token_version FROM nofi_user WHERE username = ?', [$username]);
    }
}
