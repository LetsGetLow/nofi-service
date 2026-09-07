<?php

declare(strict_types=1);

namespace Nofi\Tests\Live;

use Nofi\Entity\Notification;
use Nofi\Notification\PushTopic;
use Nofi\Service\Push\PushService;
use Kreait\Firebase\Contract\Messaging;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Throwable;

use function getenv;
use function sprintf;

/**
 * The one test that really talks to Firebase. Everything else stubs the
 * Messaging contract, which proves the application builds the right message
 * but never that the credentials, the project and the network path work.
 *
 * Excluded from a normal run in two independent ways, because it needs things
 * CI does not have and must never break a pipeline:
 *
 *   1. the "live" group, excluded by default in phpunit.dist.xml
 *   2. a skip when NOFI_LIVE_PUSH_TARGET is unset
 *
 * Nothing here may be committed. The target comes from the environment, and
 * the service account stays in config/firebase, which is gitignored:
 *
 *   NOFI_LIVE_PUSH_TARGET=/topics/test_notif_local_test_notification_v2 \
 *       ./docker vendor/bin/phpunit --group live
 *
 * A device token works just as well as a topic:
 *
 *   NOFI_LIVE_PUSH_TARGET=<registration-token> ./docker vendor/bin/phpunit --group live
 */
#[Group("live")]
#[TestDox("A real push to Firebase")]
final class RealPushTest extends KernelTestCase
{
    private string $target;

    protected function setUp(): void
    {
        $target = getenv("NOFI_LIVE_PUSH_TARGET");
        if ($target === false || $target === "") {
            self::markTestSkipped(
                'Set NOFI_LIVE_PUSH_TARGET to a device token or "'
                . PushTopic::PREFIX . '<name>" to send a real push.',
            );
        }

        $this->target = $target;
        self::bootKernel();
    }

    #[Test]
    public function firebaseAcceptsTheMessageTheApplicationBuilds(): void
    {
        // send() reports per recipient instead of raising, so a rejected
        // target arrives here as a result rather than an exception.
        $results = self::getContainer()->get(PushService::class)->send(
            new Notification("live-push-probe"),
            [$this->target],
            "nofi-service live probe",
            "Sent by " . self::class . " at " . date("c"),
            null,
            ["probe" => "true", "sentAt" => date("c")],
        );

        self::assertCount(1, $results);
        self::assertTrue(
            $results[0]["success"],
            sprintf('Firebase refused "%s": %s', $this->target, (string) $results[0]["error"]),
        );
    }

    #[Test]
    public function theCredentialsAreAcceptedWithoutDeliveringAnything(): void
    {
        // validate_only: Google checks the message and the credentials and
        // delivers nothing, so this is the safe half of the probe above.
        $messaging = self::getContainer()->get(Messaging::class);

        $message = PushTopic::isTopic($this->target)
            ? ["topic" => PushTopic::nameOf($this->target)]
            : ["token" => $this->target];

        try {
            $response = $messaging->validate([
                ...$message,
                "notification" => ["title" => "nofi-service validate only", "body" => "not delivered"],
            ]);
        } catch (Throwable $e) {
            // Caught so a wrong project, an expired key or a malformed target
            // reads as a failure with its cause instead of a Guzzle backtrace.
            self::fail(sprintf(
                'Firebase refused the message for "%s": %s',
                $this->target,
                $e->getMessage(),
            ));
        }

        self::assertIsArray($response);
    }
}
