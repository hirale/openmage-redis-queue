<?php

declare(strict_types=1);

namespace Hirale\Queue;

use Mage;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;

/**
 * Resolves a MessageClass FQCN to a Magento model alias via the merged
 * <global><hirale_queue><handlers> config.xml block, then materializes the
 * handler instance through Mage::getModel and yields it as a Symfony
 * HandlerDescriptor.
 *
 * Each downstream module declares its own handler mappings. Multiple handlers
 * per message class are not supported — exactly one handler per message,
 * matching the typical use case for this module (one downstream owns each
 * message type).
 *
 * The resolved object must be __invoke()-able with the message instance as
 * the sole argument.
 */
final class HandlersLocator implements HandlersLocatorInterface
{
    /**
     * @param array<class-string, string> $handlerMap MessageClass FQCN => Mage model alias
     */
    public function __construct(private readonly array $handlerMap)
    {
    }

    public function getHandlers(Envelope $envelope): iterable
    {
        $messageClass = $envelope->getMessage()::class;
        $alias = $this->handlerMap[$messageClass] ?? null;
        if ($alias === null) {
            return;
        }
        $handler = Mage::getModel($alias);
        if (!is_object($handler) || !is_callable($handler)) {
            return;
        }
        yield new HandlerDescriptor($handler, ['alias' => $alias]);
    }
}
