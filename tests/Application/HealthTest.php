<?php

declare(strict_types=1);

namespace Nofi\Tests\Application;

use Doctrine\DBAL\Connection;
use Nofi\Service\Push\PushCredentials;
use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The container health check. It exists because the previous one was a static
 * string served by Caddy, which stayed green while the database was gone.
 */
#[TestDox("Health endpoint")]
final class HealthTest extends ApiTestCase
{
    /**
     * No schema reset here, unlike the other API tests. /health only issues
     * SELECT 1, and touching the entity manager first would initialise the
     * connection, which then cannot be replaced with a failing one.
     */
    #[Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    #[Test]
    public function healthIsPublicAndReportsOkWhenTheDatabaseAnswers(): void
    {
        $this->withPushCredentials(present: false);

        $this->client->request('GET', '/health');

        $this->assertResponseStatus(Response::HTTP_OK);
        self::assertSame(
            ['status' => 'ok', 'database' => 'ok', 'push' => 'disabled'],
            $this->jsonResponse(),
        );
    }

    /**
     * Push is optional, so its absence is reported rather than punished: an
     * email-only deployment must stay healthy, or Compose would restart it for
     * ever. This asserts "disabled" while a real config/firebase/credentials.json
     * may well exist in the working tree, which is what proves the check reads
     * the configured path rather than guessing.
     */
    #[Test]
    public function credentialsInPlaceReportPushAsEnabledWithoutTouchingTheStatus(): void
    {
        $this->withPushCredentials(present: true);

        $this->client->request('GET', '/health');

        $this->assertResponseStatus(Response::HTTP_OK);
        self::assertSame(
            ['status' => 'ok', 'database' => 'ok', 'push' => 'enabled'],
            $this->jsonResponse(),
        );
    }

    #[Test]
    public function healthReportsServiceUnavailableWhenTheDatabaseIsUnreachable(): void
    {
        $this->withPushCredentials(present: false);

        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')
            ->willThrowException(new RuntimeException('could not connect'));
        static::getContainer()->set(Connection::class, $connection);

        $this->client->request('GET', '/health');

        // 503 is what makes the container healthcheck fail, so the
        // orchestrator stops sending traffic to it.
        $this->assertResponseStatus(Response::HTTP_SERVICE_UNAVAILABLE);
        self::assertSame(
            ['status' => 'error', 'database' => 'unreachable', 'push' => 'disabled'],
            $this->jsonResponse(),
        );
    }

    /**
     * Points the check at a path of this test's own, so the answer never
     * depends on whether the machine running the suite happens to have real
     * credentials in config/firebase.
     */
    private function withPushCredentials(bool $present): void
    {
        $relative = 'var/health-test-credentials.json';
        $absolute = self::$kernel->getProjectDir() . '/' . $relative;

        if ($present) {
            file_put_contents($absolute, '{}');
        } else {
            @unlink($absolute);
        }

        static::getContainer()->set(
            PushCredentials::class,
            new PushCredentials($relative, self::$kernel->getProjectDir()),
        );
    }

    #[Override]
    protected function tearDown(): void
    {
        @unlink(self::$kernel->getProjectDir() . '/var/health-test-credentials.json');

        parent::tearDown();
    }

    #[Test]
    public function anUnreachableDatabaseIsLogged(): void
    {
        $this->withPushCredentials(present: false);

        $connection = $this->createStub(Connection::class);
        $connection->method('executeQuery')
            ->willThrowException(new RuntimeException('could not connect'));
        static::getContainer()->set(Connection::class, $connection);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('error')
            ->with(self::stringContains('database is unreachable'), self::anything());
        static::getContainer()->set(LoggerInterface::class, $logger);

        $this->client->request('GET', '/health');

        $this->assertResponseStatus(Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
