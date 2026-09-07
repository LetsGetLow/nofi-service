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

/**
 * The only way to create an account since the seeded ones were removed, so a
 * failure here means nobody can log in at all.
 */
#[TestDox("nofi:user:create")]
final class CreateUserCommandTest extends IntegrationTestCase
{
    #[Test]
    public function itCreatesAUserWithAHashedPassword(): void
    {
        $tester = $this->tester();

        $status = $tester->execute(['username' => 'alice', '--password' => 'correct-horse']);

        self::assertSame(Command::SUCCESS, $status);
        $user = $this->find('alice');
        self::assertNotNull($user);
        self::assertSame(['ROLE_USER'], $user->getRoles());

        // The stored value must be a verifiable hash, not the password.
        self::assertNotSame('correct-horse', $user->getPassword());
        self::assertTrue(
            self::service(UserPasswordHasherInterface::class)->isPasswordValid($user, 'correct-horse'),
        );
    }

    #[Test]
    public function theAdminFlagGrantsTheAdministratorRole(): void
    {
        $this->tester()->execute(['username' => 'admin', '--password' => 'correct-horse', '--admin' => true]);

        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $this->find('admin')->getRoles());
    }

    #[Test]
    public function itPromptsForThePasswordWhenTheOptionIsOmitted(): void
    {
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
    public function itRefusesAUsernameThatAlreadyExists(): void
    {
        $this->createUser('alice');

        $tester = $this->tester();
        $status = $tester->execute(['username' => 'alice', '--password' => 'correct-horse']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('already exists', $tester->getDisplay());
    }

    #[Test]
    public function itRefusesAnEmptyPassword(): void
    {
        $tester = $this->tester();
        $status = $tester->execute(['username' => 'alice', '--password' => '   ']);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString('non-empty password', $tester->getDisplay());
        self::assertNull($this->find('alice'), 'no user may be created when the password is refused');
    }

    private function tester(): CommandTester
    {
        return new CommandTester(
            new Application(self::$kernel)->find('nofi:user:create'),
        );
    }

    private function find(string $username): ?User
    {
        self::entityManager()->clear();

        return self::service(UserRepository::class)->findOneBy(['username' => $username]);
    }
}
