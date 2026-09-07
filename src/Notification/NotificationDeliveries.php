<?php

declare(strict_types=1);

namespace Nofi\Notification;

use LogicException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Finds the delivery for a channel.
 *
 * This is what replaced the pair of near-identical message handlers: one
 * handler asks here instead of two handlers each being wired to their own
 * delivery. A channel whose delivery is missing fails loudly and says which,
 * rather than being silently undeliverable.
 */
final readonly class NotificationDeliveries
{
    /**
     * @param iterable<NotificationDelivery> $deliveries
     */
    public function __construct(
        #[AutowireIterator("nofi.notification_delivery")]
        private iterable $deliveries,
    ) {}

    public function for(NotificationChannel $channel): NotificationDelivery
    {
        foreach ($this->deliveries as $delivery) {
            if ($delivery->channel() === $channel) {
                return $delivery;
            }
        }

        throw new LogicException(sprintf(
            'No delivery is registered for the "%s" channel. Implement %s for it; '
            . 'the tag is applied automatically.',
            $channel->value,
            NotificationDelivery::class,
        ));
    }
}
