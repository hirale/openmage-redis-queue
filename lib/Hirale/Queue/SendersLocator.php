<?php

declare(strict_types=1);

namespace Hirale\Queue;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Sender\SenderInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;

/**
 * Routes envelopes to one named transport per logical queue, looked up from
 * the merged <global><hirale_queue><routing> config.xml block. Unmapped
 * message classes fall through to the "default" queue.
 *
 * The MessageBusFactory constructs the senders map by walking the configured
 * queue list once and building one transport per queue. SendersLocator is
 * cheap — pure dispatch-time lookup, no I/O.
 */
final class SendersLocator implements SendersLocatorInterface
{
    private const DEFAULT_QUEUE = 'default';

    /**
     * @param array<class-string, string> $routingMap MessageClass FQCN => queue name
     * @param array<string, SenderInterface> $senders queue name => sender
     */
    public function __construct(
        private readonly array $routingMap,
        private readonly array $senders,
    ) {
    }

    public function getSenders(Envelope $envelope): iterable
    {
        $messageClass = $envelope->getMessage()::class;
        $queueName = $this->routingMap[$messageClass] ?? self::DEFAULT_QUEUE;

        // Honor explicit TransportNamesStamp if present (e.g., producer override).
        $stamp = $envelope->last(\Symfony\Component\Messenger\Stamp\TransportNamesStamp::class);
        if ($stamp !== null) {
            foreach ($stamp->getTransportNames() as $name) {
                if (isset($this->senders[$name])) {
                    yield $name => $this->senders[$name];
                }
            }
            return;
        }

        if (isset($this->senders[$queueName])) {
            yield $queueName => $this->senders[$queueName];
        } elseif (isset($this->senders[self::DEFAULT_QUEUE])) {
            yield self::DEFAULT_QUEUE => $this->senders[self::DEFAULT_QUEUE];
        }
    }
}
