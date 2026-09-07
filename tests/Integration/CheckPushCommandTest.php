<?php

declare(strict_types=1);

namespace Nofi\Tests\Integration;

use Nofi\Service\Push\PushCredentials;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Messaging\Message;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use RuntimeException;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The real thing talks to Google, so Messaging is stubbed here and the actual
 * round trip stays in tests/Live. What is worth pinning down without a network
 * is the shape of what the command asks for, and that it says something useful
 * in each of the three outcomes.
 */
#[TestDox("nofi:push:check")]
final class CheckPushCommandTest extends IntegrationTestCase
{
    /** @var list<array<string, mixed>> */
    private array $validated = [];

    #[Test]
    public function itReportsSuccessWhenFirebaseAcceptsTheMessage(): void
    {
        $this->withCredentials(present: true);
        $this->withMessagingThatAccepts();

        $tester = $this->tester();
        $status = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $status);
        self::assertStringContainsString('accepted', self::unwrapped($tester->getDisplay()));
    }

    #[Test]
    public function itValidatesATopicByDefaultSoNoDeviceIsNeeded(): void
    {
        $this->withCredentials(present: true);
        $this->withMessagingThatAccepts();

        $this->tester()->execute([]);

        self::assertCount(1, $this->validated);
        self::assertSame('nofi-preflight', $this->validated[0]['topic'] ?? null);
        self::assertArrayNotHasKey('token', $this->validated[0]);
    }

    #[Test]
    public function aTargetWithoutTheTopicPrefixIsSentAsADeviceToken(): void
    {
        $this->withCredentials(present: true);
        $this->withMessagingThatAccepts();

        $this->tester()->execute(['target' => 'a-registration-token']);

        self::assertSame('a-registration-token', $this->validated[0]['token'] ?? null);
        self::assertArrayNotHasKey('topic', $this->validated[0]);
    }

    #[Test]
    public function anExplicitTopicIsStrippedOfItsPrefix(): void
    {
        $this->withCredentials(present: true);
        $this->withMessagingThatAccepts();

        $this->tester()->execute(['target' => '/topics/news']);

        self::assertSame('news', $this->validated[0]['topic'] ?? null);
    }

    #[Test]
    public function itFailsWithTheReasonFirebaseGave(): void
    {
        $this->withCredentials(present: true);

        $messaging = $this->createStub(Messaging::class);
        $messaging->method('validate')
            ->willThrowException(new RuntimeException('The project does not exist'));
        self::getContainer()->set(Messaging::class, $messaging);

        $tester = $this->tester();
        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(
            'The project does not exist',
            self::unwrapped($tester->getDisplay()),
        );
    }

    #[Test]
    public function itStopsBeforeFirebaseWhenNoCredentialsArePresent(): void
    {
        $this->withCredentials(present: false);
        $this->withMessagingThatAccepts();

        $tester = $this->tester();
        $status = $tester->execute([]);

        self::assertSame(Command::FAILURE, $status);
        self::assertStringContainsString(
            'No Firebase credentials',
            self::unwrapped($tester->getDisplay()),
        );
        self::assertSame([], $this->validated, 'nothing may be sent when push is not configured');
    }

    /**
     * SymfonyStyle wraps a block at the terminal width, so an error can be
     * split mid-sentence. Collapsing the whitespace lets the assertions name
     * the message rather than a fragment that happens to fit.
     */
    private static function unwrapped(string $display): string
    {
        return preg_replace('/\s+/', ' ', $display) ?? $display;
    }

    private function withMessagingThatAccepts(): void
    {
        $messaging = $this->createStub(Messaging::class);
        $messaging->method('validate')->willReturnCallback(
            function (Message|array $message): array {
                $this->validated[] = is_array($message) ? $message : $message->jsonSerialize();

                return [];
            },
        );

        self::getContainer()->set(Messaging::class, $messaging);
    }

    /**
     * Points the check at a path of this test's own, so the answer never
     * depends on whether the machine has real credentials in config/firebase.
     */
    private function withCredentials(bool $present): void
    {
        $relative = 'var/push-check-test-credentials.json';
        $absolute = self::$kernel->getProjectDir() . '/' . $relative;

        if ($present) {
            file_put_contents($absolute, '{}');
        } else {
            @unlink($absolute);
        }

        self::getContainer()->set(
            PushCredentials::class,
            new PushCredentials($relative, self::$kernel->getProjectDir()),
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        @unlink(self::$kernel->getProjectDir() . '/var/push-check-test-credentials.json');

        parent::tearDown();
    }

    private function tester(): CommandTester
    {
        return new CommandTester(
            new Application(self::$kernel)->find('nofi:push:check'),
        );
    }
}
