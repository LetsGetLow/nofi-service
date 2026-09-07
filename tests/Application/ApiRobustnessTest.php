<?php

declare(strict_types=1);

namespace Nofi\Tests\Application;

use Override;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

#[TestDox("API robustness")]
final class ApiRobustnessTest extends ApiTestCase
{
    private const string COLLECTION = '/api/v1/notifications';

    private string $token;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->token = $this->tokenFor($this->createUser('alice'));
    }

    #[Test]
    public function malformedJsonIsRejected(): void
    {
        $this->client->request(
            'POST',
            '/api/v1/notifications/send',
            server: [
                'CONTENT_TYPE' => 'application/ld+json',
                'HTTP_AUTHORIZATION' => 'Bearer ' . $this->token,
            ],
            content: '{"channel": "email", this is not json',
        );

        self::assertSame(Response::HTTP_BAD_REQUEST, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function anEmptyBodyIsRejected(): void
    {
        $this->request('POST', '/api/v1/notifications/send', $this->token, []);

        self::assertContains(
            $this->client->getResponse()->getStatusCode(),
            [Response::HTTP_BAD_REQUEST, Response::HTTP_UNPROCESSABLE_ENTITY],
            'an empty payload must not be accepted',
        );
    }

    #[Test]
    public function anUnknownNotificationIsNotFound(): void
    {
        $this->request('GET', self::COLLECTION . '/01a06341-0000-7000-8000-000000000000', $this->token);

        $this->assertResponseStatus(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function anIdThatIsNotAUuidIsNotFound(): void
    {
        $this->request('GET', self::COLLECTION . '/not-a-uuid', $this->token);

        $this->assertResponseStatus(Response::HTTP_NOT_FOUND);
    }

    #[Test]
    public function anUnsupportedMethodIsRejected(): void
    {
        $this->request('PUT', self::COLLECTION . '/01a06341-0000-7000-8000-000000000000', $this->token, []);

        self::assertSame(Response::HTTP_METHOD_NOT_ALLOWED, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function anExpiredTokenIsRejected(): void
    {
        // iat and exp in the past, signed with the real test key.
        $manager = static::getContainer()->get('lexik_jwt_authentication.jwt_manager');
        $expired = $manager->createFromPayload(
            $this->createUser('bob'),
            ['exp' => time() - 3600, 'iat' => time() - 7200],
        );

        $this->request('GET', self::COLLECTION, $expired);

        $this->assertResponseStatus(Response::HTTP_UNAUTHORIZED);
    }
}
