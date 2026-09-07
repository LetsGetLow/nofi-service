<?php

declare(strict_types=1);

namespace Nofi\Command;

use Doctrine\ORM\EntityManagerInterface;
use Nofi\Entity\User;
use Nofi\Repository\UserRepository;
use Override;
use SensitiveParameter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: "nofi:user:create",
    description: "Create a user that can authenticate against the notification API",
)]
final class CreateUserCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this
            ->addArgument("username", InputArgument::REQUIRED, "Login name of the new user")
            ->addOption("admin", null, InputOption::VALUE_NONE, "Grant ROLE_ADMIN")
            ->addOption(
                "password",
                null,
                InputOption::VALUE_REQUIRED,
                "Password. Prompted for when omitted, which keeps it out of the shell history",
            );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getArgument("username");

        if ($this->userRepository->findOneBy(["username" => $username]) !== null) {
            $io->error(sprintf('A user named "%s" already exists.', $username));

            return Command::FAILURE;
        }

        $password = $input->getOption("password") ?? $io->askHidden("Password");
        if (!is_string($password) || trim($password) === "") {
            $io->error("A non-empty password is required.");

            return Command::FAILURE;
        }

        $user = $this->createUser($username, $password, (bool) $input->getOption("admin"));

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $io->success(sprintf(
            'Created "%s" with roles: %s',
            $username,
            implode(", ", $user->getRoles()),
        ));

        return Command::SUCCESS;
    }

    private function createUser(
        string $username,
        #[SensitiveParameter] string $password,
        bool $isAdmin,
    ): User {
        $user = new User()
            ->setUsername($username)
            ->setRoles($isAdmin ? ["ROLE_ADMIN"] : ["ROLE_USER"]);

        // Hashing goes through the configured hasher so the argon2id parameters
        // in security.yaml stay the single source of truth.
        return $user->setPassword($this->passwordHasher->hashPassword($user, $password));
    }
}
