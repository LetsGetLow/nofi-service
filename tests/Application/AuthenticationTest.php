<?php

declare(strict_types=1);

namespace Nofi\Tests\Application;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;

#[TestDox("Authentication")]
final class AuthenticationTest extends ApiTestCase
{
    #[Test]
    public function validCredentialsReturnAToken(): void
    {
        $this->createUser('alice', password: 'correct-horse');

        $this->login('alice', 'correct-horse');

        $this->assertResponseStatus(Response::HTTP_OK);
        self::assertNotEmpty($this->jsonResponse()['token']);
    }

    #[Test]
    public function theReturnedTokenIsAcceptedByTheApi(): void
    {
        $this->createUser('alice', password: 'correct-horse');
        $this->login('alice', 'correct-horse');
        $token = $this->jsonResponse()['token'];

        $this->request('GET', '/api/v1/notifications', $token);

        $this->assertResponseStatus(Response::HTTP_OK);
    }

    #[Test]
    public function aWrongPasswordIsRejected(): void
    {
        $this->createUser('alice', password: 'correct-horse');

        $this->login('alice', 'wrong');

        $this->assertResponseStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function anUnknownUserIsRejected(): void
    {
        $this->login('nobody', 'correct-horse');

        $this->assertResponseStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function aTamperedTokenIsRejected(): void
    {
        $user = $this->createUser('alice');
        $token = $this->tokenFor($user);

        // Flip the last character of the signature.
        $tampered = substr($token, 0, -1) . (str_ends_with($token, 'a') ? 'b' : 'a');

        $this->request('GET', '/api/v1/notifications', $tampered);

        $this->assertResponseStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function theTokenCarriesTheUsersRoles(): void
    {
        $admin = $this->createUser('admin', ['ROLE_ADMIN'], 'correct-horse');
        $this->login('admin', 'correct-horse');

        $payload = json_decode(
            base64_decode(str_replace(['-', '_'], ['+', '/'], explode('.', $this->jsonResponse()['token'])[1])),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame('admin', $payload['username']);
        self::assertContains('ROLE_ADMIN', $payload['roles']);
        self::assertContains('ROLE_USER', $payload['roles']);
        self::assertSame($admin->getUserIdentifier(), $payload['username']);
    }

    private function login(string $username, string $password): void
    {
        $this->client->request(
            'POST',
            '/api/auth/login',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(compact('username', 'password'), JSON_THROW_ON_ERROR),
        );
    }
}
