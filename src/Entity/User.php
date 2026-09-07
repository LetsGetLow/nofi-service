<?php

declare(strict_types=1);

namespace Nofi\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nofi\Repository\UserRepository;
use Override;
use SensitiveParameter;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(UserRepository::class)]
#[ORM\Table(name: "nofi_user")]
#[ORM\UniqueConstraint(name: "uniq_nofi_user_username", columns: ["username"])]
#[UniqueEntity(fields: ["username"], message: "This username is already in use.")]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    public function __construct()
    {
        $this->id = Uuid::v7()->toRfc4122();
    }

    #[ORM\Id]
    #[ORM\Column(type: Types::GUID, unique: true)]
    private string $id;

    #[ORM\Column(length: 180)]
    private ?string $username = null;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];
    #[ORM\Column]
    private ?string $password = null;

    /**
     * Stamped into every token issued for this user and checked on each
     * request. Changing the password moves it on, which is what makes tokens
     * issued before the change stop working.
     */
    #[ORM\Column(options: ["default" => 1])]
    private int $tokenVersion = 1;

    public function getId(): string
    {
        return $this->id;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): static
    {
        $this->username = $username;

        return $this;
    }

    #[Override]
    public function getUserIdentifier(): string
    {
        return (string) $this->username;
    }

    #[Override]
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = "ROLE_USER";

        return array_values(array_unique($roles));
    }

    public function setRoles(array $roles): static
    {
        $this->roles = array_values(array_unique($roles));

        return $this;
    }

    #[Override]
    public function getPassword(): string
    {
        return (string) $this->password;
    }

    /**
     * Also revokes tokens issued against the previous password. Doing it here
     * rather than leaving it to callers means it cannot be forgotten.
     *
     * The value is already hashed, but a hash in a stack trace still reaches
     * Sentry in production, so it is redacted like the plaintext one in
     * CreateUserCommand.
     */
    public function setPassword(#[SensitiveParameter] string $password): static
    {
        if ($this->password !== null && $this->password !== $password) {
            ++$this->tokenVersion;
        }

        $this->password = $password;

        return $this;
    }

    public function getTokenVersion(): int
    {
        return $this->tokenVersion;
    }

    /**
     * Invalidates every token already issued for this user, without touching
     * the password: for a lost device or a leaked token.
     */
    public function revokeTokens(): static
    {
        ++$this->tokenVersion;

        return $this;
    }

    #[Override]
    public function eraseCredentials(): void {}
}
