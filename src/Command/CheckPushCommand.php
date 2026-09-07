<?php

declare(strict_types=1);

namespace Nofi\Command;

use Nofi\Notification\PushTopic;
use Nofi\Service\Push\PushCredentials;
use Kreait\Firebase\Contract\Messaging;
use Override;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * Answers the question /health deliberately leaves open. The endpoint reports
 * push as "enabled" when the credentials file is there, which says nothing
 * about whether Google accepts the key: proving that takes a request, and
 * /health is called every ten seconds by the container healthcheck.
 *
 * This is that request, made deliberately and only when asked. It goes through
 * validate_only, so Google checks the credentials, the project and the message
 * and then delivers nothing at all.
 */
#[AsCommand(
    name: "nofi:push:check",
    description: "Ask Firebase whether the credentials and project work, delivering nothing",
)]
final class CheckPushCommand extends Command
{
    /**
     * A topic needs no device and no subscriber to be validated, which is what
     * makes the command runnable on a fresh deployment. Named so that it is
     * obviously a probe if it ever shows up in a Firebase console.
     */
    private const string DEFAULT_TARGET = PushTopic::PREFIX . "nofi-preflight";

    public function __construct(
        private readonly Messaging $messaging,
        private readonly PushCredentials $credentials,
    ) {
        parent::__construct();
    }

    #[Override]
    protected function configure(): void
    {
        $this->addArgument(
            "target",
            InputArgument::OPTIONAL,
            'Device token, or "' . PushTopic::PREFIX . '<name>" for a topic',
            self::DEFAULT_TARGET,
        );
    }

    #[Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $target = (string) $input->getArgument("target");

        // Checked first so the common mistake — no credentials at all — is
        // named as such, rather than reaching Google and failing there.
        if (!$this->credentials->isConfigured()) {
            $io->error(sprintf(
                "No Firebase credentials at %s. Push is disabled, which /health reports too.",
                $this->credentials->absolutePath(),
            ));

            return Command::FAILURE;
        }

        try {
            $this->messaging->validate([
                ...self::addressed($target),
                "notification" => [
                    "title" => "nofi:push:check",
                    "body" => "Validated by Firebase, never delivered.",
                ],
            ]);
        } catch (Throwable $e) {
            // Reported rather than thrown: a wrong project, an expired key or
            // a stale token should read as the reason Google gave, not as a
            // Guzzle backtrace.
            $io->error(sprintf('Firebase refused the message for "%s": %s', $target, $e->getMessage()));

            return Command::FAILURE;
        }

        $io->success(sprintf(
            'Firebase accepted the message for "%s". Credentials and project are good; nothing was delivered.',
            $target,
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array{topic: string}|array{token: string}
     */
    private static function addressed(string $target): array
    {
        return PushTopic::isTopic($target)
            ? ["topic" => PushTopic::nameOf($target)]
            : ["token" => $target];
    }
}
