<?php

declare(strict_types=1);

namespace Nofi\Command;

use Nofi\Repository\UserRepository;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: "nofi:user:revoke-tokens",
    description: "Invalidate every JWT already issued for a user, without changing the password",
)]
final class RevokeUserTokensCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument(
            "username",
            InputArgument::REQUIRED,
            "Login name of the user whose tokens are revoked",
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

        // Goes through the repository rather than User::revokeTokens(), which
        // works the new number out in PHP: the increment belongs in the
        // database so that a revocation this process never saw cannot be
        // undone. UserRepository::revokeTokens() explains the race in full.
        $this->userRepository->revokeTokens($user);

        $io->success(sprintf(
            'Revoked every token issued for "%s". Its token version is now %d.',
            $username,
            $user->getTokenVersion(),
        ));

        return Command::SUCCESS;
    }
}
