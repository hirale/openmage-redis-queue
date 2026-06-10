<?php

declare(strict_types=1);

namespace Hirale\Queue\Middleware;

use Hirale\Queue\MessageBusFactory;
use Mage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * Opt-in dispatcher-side observability. Logs dispatched messages to the Maho
 * application log when audit is enabled in admin. Cheap noop on the consumer
 * side and when audit is off. The DB audit (admin actions) is handled by
 * Hirale_Queue_Model_Audit, not this middleware.
 */
final class AuditMiddleware implements MiddlewareInterface
{
    public function handle(Envelope $envelope, StackInterface $stack): Envelope
    {
        if ($envelope->last(ReceivedStamp::class) !== null) {
            return $stack->next()->handle($envelope, $stack);
        }
        $helper = MessageBusFactory::helper();
        if ($helper->isAuditAdminActionsEnabled()) {
            $message = $envelope->getMessage();
            Mage::log(
                sprintf('hirale_queue dispatched %s', $message::class),
                \Monolog\Level::Debug->value,
                'hirale_queue.log',
            );
        }
        return $stack->next()->handle($envelope, $stack);
    }
}
