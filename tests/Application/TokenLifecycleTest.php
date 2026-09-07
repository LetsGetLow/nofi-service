<?php

declare(strict_types=1);

namespace Nofi\Tests\Application;

use Nofi\Entity\User;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Tokens are stateless and live for an hour, so what still applies to an
 * already issued one decides whether revocation is needed.
 */
#[TestDox("What an issued token still respects")]
final class TokenLifecycleTest extends ApiTestCase
{
    #[Test]
    public function aTokenStopsWorkingOnceTheUserIsDeleted(): void
    {
        $user = $this->createUser('alice');
        $token = $this->tokenFor($user);

        $this->request('GET', '/api/v1/notifications', $token);
        $this->assertResponseStatus(Response::HTTP_OK);

        self::entityManager()->remove($user);
        self::entityManager()->flush();

        $this->request('GET', '/api/v1/notifications', $token);
        $this->assertResponseStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function revokingAdminAppliesToAnAlreadyIssuedToken(): void
    {
        $admin = $this->createUser('admin', ['ROLE_ADMIN']);
        $token = $this->tokenFor($admin);

        $alice = $this->createUser('alice');
        $this->request('POST', '/api/v1/notifications/send', $this->tokenFor($alice), [
            'channel' => 'email',
            'sender' => 'noreply@example.com',
            'subject' => 's',
            'message' => 'm',
            'recipients' => ['ops@example.com'],
        ]);
        $id = $this->jsonResponse()['@id'];

        $this->request('DELETE', $id, $token);
        $this->assertResponseStatus(Response::HTTP_NO_CONTENT);

        // Re-fetch: the browser reboots the kernel on each request, so the
        // entity captured earlier is detached and flushing it would be a
        // silent no-op.
        $demoted = self::entityManager()->getRepository(User::class)
            ->findOneBy(['username' => 'admin']);
        $demoted->setRoles(['ROLE_USER']);
        self::entityManager()->flush();

        $this->request('POST', '/api/v1/notifications/send', $this->tokenFor($alice), [
            'channel' => 'email',
            'sender' => 'noreply@example.com',
            'subject' => 's',
            'message' => 'm',
            'recipients' => ['ops@example.com'],
        ]);
        $second = $this->jsonResponse()['@id'];

        $this->request('DELETE', $second, $token);
        self::assertSame(
            Response::HTTP_FORBIDDEN,
            $this->client->getResponse()->getStatusCode(),
            'roles must come from the database, not from the claims in the token',
        );
    }

    #[Test]
    public function aPasswordChangeInvalidatesTokensIssuedBeforeIt(): void
    {
        $user = $this->createUser('alice', password: 'old-password');
        $token = $this->tokenFor($user);

        $stored = self::entityManager()->getRepository(User::class)
            ->findOneBy(['username' => 'alice']);
        $stored->setPassword(
            static::getContainer()
                ->get(UserPasswordHasherInterface::class)
                ->hashPassword($stored, 'new-password'),
        );
        self::entityManager()->flush();

        $this->request('GET', '/api/v1/notifications', $token);

        // The token is still inside its hour, but it was issued against the
        // previous password.
        $this->assertResponseStatus(Response::HTTP_UNAUTHORIZED);
    }

    #[Test]
    public function aTokenIssuedAfterThePasswordChangeWorks(): void
    {
        $user = $this->createUser('alice', password: 'old-password');

        $stored = self::entityManager()->getRepository(User::class)
            ->findOneBy(['username' => 'alice']);
        $stored->setPassword(
            static::getContainer()
                ->get(UserPasswordHasherInterface::class)
                ->hashPassword($stored, 'new-password'),
        );
        self::entityManager()->flush();

        $this->request('GET', '/api/v1/notifications', $this->tokenFor($stored));

        $this->assertResponseStatus(Response::HTTP_OK);
    }

    #[Test]
    public function tokensCanBeRevokedWithoutChangingThePassword(): void
    {
        $user = $this->createUser('alice');
        $token = $this->tokenFor($user);

        $this->request('GET', '/api/v1/notifications', $token);
        $this->assertResponseStatus(Response::HTTP_OK);

        $stored = self::entityManager()->getRepository(User::class)
            ->findOneBy(['username' => 'alice']);
        $stored->revokeTokens();
        self::entityManager()->flush();

        $this->request('GET', '/api/v1/notifications', $token);
        $this->assertResponseStatus(Response::HTTP_UNAUTHORIZED);
    }
}
