<?php

declare(strict_types=1);

namespace Nofi\Tests\Unit\Notification;

use Nofi\Notification\NotificationStatus;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestDox;
use PHPUnit\Framework\TestCase;

#[TestDox("NotificationStatus enum behavior")]
class NotificationStatusTest extends TestCase
{
    #[Test]
    public function isFinalDeterminesIfStatusIsAFinalState(): void
    {
        $this->assertFalse(NotificationStatus::CREATED->isFinal());
        $this->assertFalse(NotificationStatus::QUEUED->isFinal());
        $this->assertFalse(NotificationStatus::PROCESSING->isFinal());
        $this->assertTrue(NotificationStatus::FAILED->isFinal());
        $this->assertTrue(NotificationStatus::SENT->isFinal());
    }

    #[Test]
    public function isWaitingDeterminesIfStatusIsAWaitingState(): void
    {
        $this->assertTrue(NotificationStatus::CREATED->isWaiting());
        $this->assertTrue(NotificationStatus::QUEUED->isWaiting());
        $this->assertFalse(NotificationStatus::PROCESSING->isWaiting());
        $this->assertFalse(NotificationStatus::FAILED->isWaiting());
        $this->assertFalse(NotificationStatus::SENT->isWaiting());
    }

    #[Test]
    public function isResendableDeterminesIfStatusCanBeResent(): void
    {
        $this->assertFalse(NotificationStatus::CREATED->isResendable());
        $this->assertFalse(NotificationStatus::QUEUED->isResendable());
        $this->assertFalse(NotificationStatus::PROCESSING->isResendable());
        $this->assertFalse(NotificationStatus::SENT->isResendable());
        $this->assertTrue(NotificationStatus::FAILED->isResendable());
    }

    #[Test]
    public function canTransitionToDeterminesAllowedLifecycleMoves(): void
    {
        $this->assertTrue(NotificationStatus::CREATED->canTransitionTo(NotificationStatus::QUEUED));
        $this->assertTrue(NotificationStatus::QUEUED->canTransitionTo(NotificationStatus::QUEUED));
        $this->assertTrue(NotificationStatus::QUEUED->canTransitionTo(NotificationStatus::PROCESSING));
        $this->assertTrue(NotificationStatus::PROCESSING->canTransitionTo(NotificationStatus::SENT));
        $this->assertTrue(NotificationStatus::PROCESSING->canTransitionTo(NotificationStatus::FAILED));
        $this->assertFalse(NotificationStatus::SENT->canTransitionTo(NotificationStatus::FAILED));
        $this->assertFalse(NotificationStatus::SENT->canTransitionTo(NotificationStatus::FAILED));
    }

    #[Test]
    public function allowedTransitionsReturnsTheExpectedNextStates(): void
    {
        $this->assertSame(
            [
                NotificationStatus::QUEUED,
                NotificationStatus::PROCESSING,
                NotificationStatus::FAILED,
                NotificationStatus::CANCELLED,
            ],
            NotificationStatus::CREATED->allowedTransitions(),
        );
        $this->assertSame([], NotificationStatus::SENT->allowedTransitions());
    }

    #[Test]
    public function aSendCanBeCancelledUntilAWorkerPicksItUp(): void
    {
        self::assertTrue(NotificationStatus::CREATED->canTransitionTo(NotificationStatus::CANCELLED));
        self::assertTrue(NotificationStatus::QUEUED->canTransitionTo(NotificationStatus::CANCELLED));

        // Delivery has begun, so cancelling would race it rather than prevent
        // anything, and what is over cannot be undone.
        self::assertFalse(NotificationStatus::PROCESSING->canTransitionTo(NotificationStatus::CANCELLED));
        self::assertFalse(NotificationStatus::SENT->canTransitionTo(NotificationStatus::CANCELLED));
        self::assertFalse(NotificationStatus::FAILED->canTransitionTo(NotificationStatus::CANCELLED));
    }

    #[Test]
    public function aCancelledSendIsOverAndGoesNowhere(): void
    {
        self::assertTrue(NotificationStatus::CANCELLED->isFinal());
        self::assertFalse(NotificationStatus::CANCELLED->isWaiting());
        self::assertFalse(NotificationStatus::CANCELLED->isResendable());
        self::assertSame([], NotificationStatus::CANCELLED->allowedTransitions());
    }

    #[Test]
    public function aFailedSendMayStillBeDeliveredSoARetryIsNotSkipped(): void
    {
        // The send handlers ask exactly this before delivering, so a retry of
        // a failed notification has to answer true here.
        self::assertTrue(NotificationStatus::FAILED->canTransitionTo(NotificationStatus::PROCESSING));
        self::assertTrue(NotificationStatus::QUEUED->canTransitionTo(NotificationStatus::PROCESSING));
        self::assertFalse(NotificationStatus::CANCELLED->canTransitionTo(NotificationStatus::PROCESSING));
        self::assertFalse(NotificationStatus::SENT->canTransitionTo(NotificationStatus::PROCESSING));
    }
}
