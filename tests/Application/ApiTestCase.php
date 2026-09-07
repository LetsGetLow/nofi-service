<?php

declare(strict_types=1);

namespace Nofi\Tests\Application;

use Nofi\Entity\User;
use Nofi\Tests\Support\InteractsWithDatabase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Override;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DependencyInjection\ContainerInterface;

abstract class ApiTestCase extends WebTestCase
{
    use InteractsWithDatabase;

    protected KernelBrowser $client;

    #[Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->resetDatabase();
    }

    #[Override]
    protected static function testContainer(): ContainerInterface
    {
        return static::getContainer();
    }

    /**
     * A real signed token rather than a stubbed security context, so the
     * firewall, the authenticator and the access rules are all exercised.
     */
    protected function tokenFor(User $user): string
    {
        return static::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function request(
        string $method,
        string $uri,
        ?string $token = null,
        ?array $body = null,
    ): void {
        $headers = ["CONTENT_TYPE" => "application/ld+json"];
        if ($token !== null) {
            $headers["HTTP_AUTHORIZATION"] = "Bearer " . $token;
        }

        $this->client->request(
            $method,
            $uri,
            server: $headers,
            content: $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function jsonResponse(): array
    {
        return json_decode(
            (string) $this->client->getResponse()->getContent(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
    }

    protected function assertResponseStatus(int $expected): void
    {
        self::assertSame(
            $expected,
            $this->client->getResponse()->getStatusCode(),
            (string) $this->client->getResponse()->getContent(),
        );
    }
}
