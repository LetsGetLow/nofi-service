<?php

declare(strict_types=1);

namespace Nofi\Tests\Integration;

use Nofi\Entity\User;
use Nofi\Repository\UserRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[TestDox("nofi:user:set-password")]
final class SetUserPasswordCommandTest extends IntegrationTestCase
{
    #[Test]
    public function itStoresAHashOfTheNewPassword(): void
    {
        $this->createUser('alice', password: 'old-password');

        $tester = $this->tester();
        $status = $tester->execute(['username' => 'alice', '--password' => 'new-password']);

        self::assertSame(Command::SUCCESS, $status);

        $user = $this->find('alice');
        $hasher = self::service(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, 'new-password'));
        self::assertFalse($hasher->isPasswordValid($user, 'old-password'));
        self::assertNotSame('new-password', $user->getPassword());
    }

    #[Test]
    public function itRevokesTheTokensIssuedAgainstTheOldPassword(): void
    {
        $this->createUser('alice', password: 'old-password');
        $before = $this->storedVersion('alice');

        $this->tester()->execute(['username' => 'alice', '--password' => 'new-password']);

        // The point of the command beyond the password itself: an existing
        // token must stop working, which is what the counter does.
        self::assertSame($before + 1, $this->storedVersion('alice'));
    }

    #[Test]
    public function itPromptsForThePasswordWhenTheOptionIsOmitted(): void
    {
        $this->createUser('alice');

        $tester = $this->tester();
        $tester->setInputs(['from-the-prompt']);
        $status = $tester->execute(['username' => 'alice']);

        self::assertSame(Command::SUCCESS, $status);
        self::assertTrue(
            self::service(UserPasswordHasherInterface::class)
                ->isPasswordValid($this->find('alice'), 'from-the-prompt'),
        );
    }

    #[Test]
    public function itRefusesAnEmptyPassword(): void
    {
        $this->createUser('alice', password: 'old-password');

        $tester = $this->tester();
        $status = $tester->execute(['username' => 'alice', '--password' => '   ']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('non-empty password', $tester->getDisplay());
        self::assertTrue(
            self::service(UserPasswordHasherInterface::class)
                ->isPasswordValid($this->find('alice'), 'old-password'),
            'the old password must still work when the new one is refused',
        );
        self::assertSame(1, $this->storedVersion('alice'), 'and no token may be revoked');
    }

    #[Test]
    public function itReportsAUsernameThatDoesNotExist(): void
    {
        $tester = $this->tester();
        $status = $tester->execute(['username' => 'nobody', '--password' => 'irrelevant']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('No user named', $tester->getDisplay());
    }

    #[Test]
    public function itLeavesTheOtherUsersAlone(): void
    {
        $this->createUser('alice', password: 'alice-password');
        $this->createUser('bob', password: 'bob-password');

        $this->tester()->execute(['username' => 'alice', '--password' => 'new-password']);

        self::assertTrue(
            self::service(UserPasswordHasherInterface::class)
                ->isPasswordValid($this->find('bob'), 'bob-password'),
        );
        self::assertSame(1, $this->storedVersion('bob'));
    }

    private function tester(): CommandTester
    {
        return new CommandTester(
            new Application(self::$kernel)->find('nofi:user:set-password'),
        );
    }

    private function find(string $username): User
    {
        self::entityManager()->clear();

        $user = self::service(UserRepository::class)->findOneBy(['username' => $username]);
        self::assertNotNull($user);

        return $user;
    }

    private function storedVersion(string $username): int
    {
        return (int) self::entityManager()->getConnection()
            ->fetchOne('SELECT token_version FROM nofi_user WHERE username = ?', [$username]);
    }
}
