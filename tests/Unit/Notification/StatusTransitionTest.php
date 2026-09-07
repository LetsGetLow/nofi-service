<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Notification;

use Nofi\Entity\Notification;
use Nofi\Entity\NotificationRecipient;
use Nofi\Notification\NotificationStatus;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

/**
 * The state machine refuses moves that would misreport what happened, for
 * instance putting an already sent notification back in the queue.
 */
#[TestDox("Status transitions that must be refused")]
final class StatusTransitionTest extends TestCase
{
    #[Test]
    public function aNotificationCannotGoBackToQueuedOnceSent(): void
    {
        $notification = new Notification('n-1')->markQueued()->markProcessing()->markSent();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot transition notification from sent to queued');
        $notification->markQueued();
    }

    #[Test]
    public function aSentNotificationIsFinal(): void
    {
        $notification = new Notification('n-1')->markQueued()->markProcessing()->markSent();

        $this->expectException(LogicException::class);
        $notification->markFailed();
    }

    #[Test]
    public function aNotificationCannotJumpStraightFromQueuedToSent(): void
    {
        $notification = new Notification('n-1')->markQueued();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot transition notification from queued to sent');
        $notification->markSent();
    }

    #[Test]
    public function aFailedNotificationMayBeAttemptedAgain(): void
    {
        $notification = new Notification('n-1')->markQueued()->markProcessing()->markFailed();

        // How a retry actually arrives: Messenger hands the same message back
        // and delivery starts again. Not through queued — nothing
        // re-dispatches a message, so that edge would only have promised a
        // resend the API does not offer.
        self::assertSame(NotificationStatus::PROCESSING, $notification->markProcessing()->getStatus());
    }

    #[Test]
    public function aFailedNotificationCannotBePutBackInTheQueue(): void
    {
        $notification = new Notification('n-1')->markQueued()->markProcessing()->markFailed();

        $this->expectException(LogicException::class);
        $notification->markQueued();
    }

    #[Test]
    public function aRecipientCannotGoBackToQueuedOnceSent(): void
    {
        $recipient = new NotificationRecipient()->markProcessing()->markSent();

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Cannot transition recipient from sent to queued');
        $recipient->markQueued();
    }

    #[Test]
    public function aRecipientCannotJumpStraightFromQueuedToSent(): void
    {
        $recipient = new NotificationRecipient();

        $this->expectException(LogicException::class);
        $recipient->markSent();
    }

    #[Test]
    public function transitioningToTheSameStatusIsAllowed(): void
    {
        $notification = new Notification('n-1')->markQueued();

        self::assertSame(NotificationStatus::QUEUED, $notification->markQueued()->getStatus());
    }

    #[Test]
    public function everyRecipientGetsItsOwnIdentifier(): void
    {
        $notification = new Notification('n-1');
        $notification->addRecipient('ops@example.com');
        $notification->addRecipient('dev@example.com');

        $ids = array_map(
            static fn (NotificationRecipient $r): string => $r->getId(),
            $notification->getRecipients()->toArray(),
        );

        self::assertCount(2, array_unique($ids));
        self::assertNotSame('', $ids[0]);
    }
}
