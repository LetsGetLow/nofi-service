<?php

declare(strict_types=1);

namespace Nofi\Command;

use Nofi\Entity\User;
use Nofi\Repository\UserRepository;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

#[AsCommand(
    name: "nofi:user:list",
    description: "List the accounts that can authenticate against the notification API",
)]
final class ListUsersCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Ordered by id, which is creation order: the ids are UUID v7, whose
        // leading bits are a millisecond timestamp. That is also where the
        // "created" column comes from, the entity storing no date of its own.
        $users = $this->userRepository->findBy([], ["id" => "ASC"]);

        if ($users === []) {
            $io->warning("No users exist. Create one with nofi:user:create.");

            return Command::SUCCESS;
        }

        $io->table(
            ["username", "roles", "token version", "created"],
            array_map(self::row(...), $users),
        );

        return Command::SUCCESS;
    }

    /**
     * @return array{string, string, int, string}
     */
    private static function row(User $user): array
    {
        return [
            (string) $user->getUsername(),
            implode(", ", $user->getRoles()),
            $user->getTokenVersion(),
            self::createdAt($user),
        ];
    }

    /**
     * The password is deliberately absent, and so is the id: a hash in a
     * terminal is no use to anybody, and the id is only ever compared inside a
     * token. Ask for one with dbal:run-sql on the rare occasion it is needed.
     */
    private static function createdAt(User $user): string
    {
        $uuid = Uuid::fromString($user->getId());

        // Every account this application creates has a v7 id, but a row put
        // there by hand need not, and a missing date is better than a crash in
        // a command whose job is to show what is in the table.
        return $uuid instanceof UuidV7
            ? $uuid->getDateTime()->format("Y-m-d H:i")
            : "—";
    }
}
