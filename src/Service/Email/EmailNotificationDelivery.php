<?php

declare(strict_types=1);

namespace Nofi\Service\Email;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Nofi\Entity\Notification;
use Nofi\Notification\EmailNotificationPayload;
use Nofi\Notification\MailTemplateLocator;
use Nofi\Notification\NotificationStatus;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Throwable;

readonly class EmailNotificationDelivery
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private MailTemplateLocator $templates,
    ) {}

    public function deliver(Notification $notification, EmailNotificationPayload $payload): void
    {
        $notification->markProcessing();
        $attempted = 0;
        $failed = 0;

        // Rendered once: the body is the same for every recipient, and a
        // broken template must fail the whole notification rather than
        // partially deliver it.
        $body = $this->renderBody($notification, $payload);

        foreach ($notification->getRecipients() as $recipient) {
            $status = $recipient->getStatus();
            if (!$status->isWaiting() && !$status->isResendable()) {
                continue;
            }

            ++$attempted;

            try {
                $recipient->markProcessing();
                $email = new Email()
                    ->priority(Email::PRIORITY_NORMAL)
                    ->from($payload->sender)
                    ->to($recipient->getRecipient() ?? throw new RuntimeException("Recipient is missing."))
                    ->subject($payload->subject)
                    ->html($body)
                    ->text(strip_tags($body));

                foreach ($payload->attachments as $attachment) {
                    if ($attachment->isInline()) {
                        // Embedded in the HTML body, referenced as cid:<contentId>.
                        $email->embed(
                            $attachment->content,
                            (string) $attachment->contentId,
                            $attachment->contentType,
                        );

                        continue;
                    }

                    $email->attach(
                        $attachment->content,
                        $attachment->filename,
                        $attachment->contentType,
                    );
                }

                $this->mailer->send($email);
                $recipient->markSent();
                $recipient->markSentAt(new DateTimeImmutable());
            } catch (Throwable $e) {
                $recipient->markFailed();
                ++$failed;
                $this->logger->error("Email notification delivery failed for a recipient.", [
                    "notification" => $notification->getId(),
                    "recipient" => $recipient->getRecipient(),
                    "exception" => $e,
                ]);
            }
        }

        $notification->transitionToStatus($failed > 0 ? NotificationStatus::FAILED : NotificationStatus::SENT);
        $this->entityManager->flush();
        $this->entityManager->clear();

        if ($failed > 0) {
            // Thrown so Messenger retries; recipients already sent are skipped
            // on the next attempt because only waiting or failed ones qualify.
            throw new RuntimeException(sprintf(
                "Email notification %s failed for %d of %d recipients.",
                $notification->getId(),
                $failed,
                $attempted,
            ));
        }
    }

    /**
     * With no template the message is the body, which is how every existing
     * caller works. With one, the message becomes a variable inside it.
     */
    private function renderBody(Notification $notification, EmailNotificationPayload $payload): string
    {
        if ($payload->template === null) {
            return $payload->message;
        }

        try {
            return $this->templates->render($payload->template, [
                ...$payload->data,
                "subject" => $payload->subject,
                "message" => $payload->message,
            ]);
        } catch (Throwable $e) {
            $this->logger->error("Email notification template failed to render.", [
                "notification" => $notification->getId(),
                "template" => $payload->template,
                "exception" => $e,
            ]);

            throw new RuntimeException(sprintf(
                'Email notification %s could not render template "%s".',
                $notification->getId(),
                $payload->template,
            ), previous: $e);
        }
    }
}
