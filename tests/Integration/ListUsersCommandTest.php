<?php

declare(strict_types=1);

namespace Nofi\Tests\Integration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

#[TestDox("nofi:user:list")]
final class ListUsersCommandTest extends IntegrationTestCase
{
    #[Test]
    public function itShowsTheUsernameAndRoles(): void
    {
        $this->createUser('alice', roles: ['ROLE_ADMIN']);

        $tester = $this->tester();
        $status = $tester->execute([]);
        $display = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('alice', $display);
        self::assertStringContainsString('ROLE_ADMIN', $display);
    }

    #[Test]
    public function itShowsTheTokenVersionTheRowHolds(): void
    {
        $this->createUser('alice');
        self::entityManager()->getConnection()
            ->executeStatement("UPDATE nofi_user SET token_version = 7 WHERE username = 'alice'");

        // The statement went past the ORM, so the identity map still holds the
        // entity this test created. A real invocation boots with an empty one.
        self::entityManager()->clear();

        $this->tester()->execute([]);

        self::assertStringContainsString('7', $this->display());
    }

    #[Test]
    public function itDatesEachAccountFromItsUuid(): void
    {
        $this->createUser('alice');

        $this->tester()->execute([]);

        // The entity stores no date; this comes out of the v7 id.
        self::assertMatchesRegularExpression('/\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $this->display());
    }

    #[Test]
    public function itNeverPrintsThePasswordHash(): void
    {
        $user = $this->createUser('alice');

        $this->tester()->execute([]);

        self::assertStringNotContainsString($user->getPassword(), $this->display());
    }

    #[Test]
    public function itListsInCreationOrder(): void
    {
        $this->createUser('first');
        $this->createUser('second');

        $this->tester()->execute([]);
        $display = $this->display();

        self::assertLessThan(
            strpos($display, 'second'),
            strpos($display, 'first'),
            'the v7 ids order the table by creation time',
        );
    }

    #[Test]
    public function itSaysSoWhenTheTableIsEmpty(): void
    {
        $tester = $this->tester();
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('No users exist', $tester->getDisplay());
    }

    private ?CommandTester $tester = null;

    private function tester(): CommandTester
    {
        return $this->tester ??= new CommandTester(
            new Application(self::$kernel)->find('nofi:user:list'),
        );
    }

    private function display(): string
    {
        return $this->tester()->getDisplay();
    }
}
