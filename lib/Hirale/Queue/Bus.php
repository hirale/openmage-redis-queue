<?php

declare(strict_types=1);

namespace Hirale\Queue;

use Hirale\Queue\Stamp\RetryPolicyStamp;
use Hirale\Queue\Stamp\StoreScopeStamp;
use Mage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\StampInterface;
use Symfony\Component\Messenger\Stamp\TransportNamesStamp;

/**
 * The producer entry point. Downstream code calls Bus::dispatch($message)
 * and the per-store retry policy + dispatching store scope are captured
 * automatically into stamps the rest of the pipeline reads.
 *
 *   use Hirale\Queue\Bus;
 *   Bus::dispatch(new DrainEventsMessage(reason: 'index_events'));
 *
 * The static API is intentional — Maho has no DI container we can inject
 * the bus into, and operators expect the producer-side surface to feel
 * "just call this from anywhere", matching Mage::log() / Mage::dispatchEvent().
 */
final class Bus
{
    /**
     * @param list<StampInterface> $stamps additional envelope stamps
     */
    public static function dispatch(object $message, array $stamps = []): Envelope
    {
        // array_merge, not `+`: both arrays are numeric lists, and union would
        // silently drop caller stamps that collide with default indexes.
        return self::messageBus()->dispatch($message, array_merge(self::defaultStamps(), $stamps));
    }

    /**
     * Dispatch with an explicit queue override, bypassing the configured
     * routing for this message class. Useful for testing or one-off ops.
     *
     * @param list<StampInterface> $extraStamps
     */
    public static function dispatchOnQueue(object $message, string $queueName, array $extraStamps = []): Envelope
    {
        $stamps = self::defaultStamps();
        $stamps[] = new TransportNamesStamp([$queueName]);
        foreach ($extraStamps as $stamp) {
            $stamps[] = $stamp;
        }
        return self::messageBus()->dispatch($message, $stamps);
    }

    /**
     * Dispatch after a delay. $delaySeconds becomes a DelayStamp; the transport
     * holds the envelope back until the deadline.
     *
     * @param list<StampInterface> $extraStamps
     */
    public static function dispatchDelayed(object $message, int $delaySeconds, array $extraStamps = []): Envelope
    {
        $stamps = self::defaultStamps();
        $stamps[] = new DelayStamp(max(0, $delaySeconds) * 1000);
        foreach ($extraStamps as $stamp) {
            $stamps[] = $stamp;
        }
        return self::messageBus()->dispatch($message, $stamps);
    }

    public static function messageBus(): MessageBusInterface
    {
        return MessageBusFactory::getBus();
    }

    /**
     * @return list<StampInterface>
     */
    private static function defaultStamps(): array
    {
        $storeId = null;
        try {
            $store = Mage::app()->getStore();
            $storeId = $store && !$store->isAdmin() ? (int) $store->getId() : null;
        } catch (\Throwable) {
            // Boot-time call before app is initialized — treat as global scope.
        }
        $helper = MessageBusFactory::helper();
        return [
            new StoreScopeStamp($storeId),
            new RetryPolicyStamp(
                $helper->getRetryMaxAttempts($storeId),
                $helper->getRetryBackoffBaseSeconds($storeId),
                $helper->getRetryBackoffCapSeconds($storeId),
            ),
        ];
    }
}
