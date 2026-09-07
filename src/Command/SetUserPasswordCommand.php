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
    name: "nofi:user:set-password",
    description: "Change a user's password, which also invalidates every token issued for it",
)]
final class SetUserPasswordCommand extends Command
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
            ->addArgument("username", InputArgument::REQUIRED, "Login name of the user")
            ->addOption(
                "password",
                null,
                InputOption::VALUE_REQUIRED,
                "New password. Prompted for when omitted, which keeps it out of the shell history",
            );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $username = (string) $input->getArgument("username");

        $user = $this->userRepository->findOneBy(["username" => $username]);
        if ($user === null) {
            $io->error(sprintf('No user named "%s" exists.', $username));

            return Command::FAILURE;
        }

        $password = $input->getOption("password") ?? $io->askHidden("New password");
        if (!is_string($password) || trim($password) === "") {
            $io->error("A non-empty password is required.");

            return Command::FAILURE;
        }

        $this->setPassword($user, $password);

        $io->success(sprintf(
            'Changed the password for "%s". Every token issued for it is now rejected.',
            $username,
        ));

        return Command::SUCCESS;
    }

    /**
     * Hashing goes through the configured hasher so the argon2id parameters in
     * security.yaml stay the single source of truth, exactly as in
     * CreateUserCommand. User::setPassword() moves the token version on by
     * itself, so there is nothing to remember here: a changed password cannot
     * leave an old session alive.
     */
    private function setPassword(User $user, #[SensitiveParameter] string $password): void
    {
        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $this->entityManager->flush();
    }
}
