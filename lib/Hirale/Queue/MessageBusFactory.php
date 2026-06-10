<?php

declare(strict_types=1);

namespace Hirale\Queue;

use Hirale\Queue\Middleware\AuditMiddleware;
use Hirale\Queue\Middleware\JobRepositoryMiddleware;
use Mage;
use RuntimeException;
use Symfony\Component\Messenger\Bridge\Redis\Transport\RedisTransportFactory;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Middleware\AddBusNameStampMiddleware;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Middleware\SendMessageMiddleware;
use Symfony\Component\Messenger\Transport\Serialization\PhpSerializer;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Builds the MessageBus and transports from admin config. Cached per-process
 * because the assembly walks the merged Mage config XML, which is non-trivial.
 *
 * Maho has no DI container (commands instantiate via bare `new`), so all the
 * service wiring lives here in a static factory. `Hirale\Queue\Bus` is the
 * thin façade producers and consumers actually call.
 */
final class MessageBusFactory
{
    private const BUS_NAME = 'hirale_queue';

    private static ?MessageBusInterface $bus = null;

    /** @var array<string, TransportInterface> */
    private static array $transports = [];

    /** @var array<class-string, string>|null */
    private static ?array $handlerMap = null;

    /** @var array<class-string, string>|null */
    private static ?array $routingMap = null;

    public static function getBus(): MessageBusInterface
    {
        return self::$bus ??= self::buildBus();
    }

    public static function getTransport(string $queueName): TransportInterface
    {
        return self::$transports[$queueName] ??= self::buildTransport($queueName);
    }

    /**
     * @param list<string> $queueNames
     * @return array<string, TransportInterface>
     */
    public static function getReceivers(array $queueNames): array
    {
        $receivers = [];
        foreach ($queueNames as $name) {
            $receivers[$name] = self::getTransport($name);
        }
        return $receivers;
    }

    public static function getRetryStrategy(): RetryStrategy
    {
        $helper = self::helper();
        return new RetryStrategy(
            $helper->getRetryMaxAttempts(),
            $helper->getRetryBackoffBaseSeconds(),
            $helper->getRetryBackoffCapSeconds(),
        );
    }

    public static function getDsnBuilder(): TransportDsnBuilder
    {
        return new TransportDsnBuilder();
    }

    /**
     * Typed accessor for the module helper — Mage::helper() is typed as
     * Mage_Core_Helper_Abstract|false, which every caller would otherwise
     * have to narrow.
     */
    public static function helper(): \Hirale_Queue_Helper_Data
    {
        $helper = Mage::helper('hirale_queue');
        if (!$helper instanceof \Hirale_Queue_Helper_Data) {
            throw new RuntimeException('Hirale_Queue helper is unavailable.');
        }
        return $helper;
    }

    /**
     * @return array<class-string, string>
     */
    public static function getHandlerMap(): array
    {
        return self::$handlerMap ??= self::parseConfigMap('global/hirale_queue/handlers');
    }

    /**
     * @return array<class-string, string>
     */
    public static function getRoutingMap(): array
    {
        return self::$routingMap ??= self::parseConfigMap('global/hirale_queue/routing');
    }

    public static function reset(): void
    {
        self::$bus = null;
        self::$transports = [];
        self::$handlerMap = null;
        self::$routingMap = null;
    }

    /**
     * Test seam: swap in a recording/stub bus so producer code paths can be
     * asserted without a transport. Cleared by reset(). Production code never
     * calls this.
     */
    public static function replaceBus(MessageBusInterface $bus): void
    {
        self::$bus = $bus;
    }

    private static function buildBus(): MessageBusInterface
    {
        $helper = self::helper();
        $senders = [];
        foreach ($helper->getQueueList() as $queueName) {
            $senders[$queueName] = self::getTransport($queueName);
        }

        $sendersLocator  = new SendersLocator(self::getRoutingMap(), $senders);
        $handlersLocator = new HandlersLocator(self::getHandlerMap());

        return new MessageBus([
            new AddBusNameStampMiddleware(self::BUS_NAME),
            new JobRepositoryMiddleware(),
            new AuditMiddleware(),
            new SendMessageMiddleware($sendersLocator),
            new HandleMessageMiddleware($handlersLocator),
        ]);
    }

    private static function buildTransport(string $queueName): TransportInterface
    {
        $helper      = self::helper();
        $backendCfg  = $helper->getBackendConfig();
        $dsnBuilder  = self::getDsnBuilder();
        $dsn         = $dsnBuilder->build($backendCfg, $queueName);
        $serializer  = new PhpSerializer();
        $backendType = (string) ($backendCfg['type'] ?? 'redis');

        switch ($backendType) {
            case 'redis':
                if (!class_exists(RedisTransportFactory::class)) {
                    throw new RuntimeException('symfony/redis-messenger is not installed.');
                }
                return (new RedisTransportFactory())->createTransport($dsn, [], $serializer);

            case 'doctrine':
                throw new RuntimeException(
                    'Doctrine backend requires `composer require symfony/doctrine-messenger` ' .
                    'and a DBAL connection. Not wired in v3.0; track in a follow-up.',
                );

            case 'amqp':
                throw new RuntimeException(
                    'AMQP backend requires `composer require symfony/amqp-messenger`. ' .
                    'Not wired in v3.0; track in a follow-up.',
                );

            case 'sqs':
                throw new RuntimeException(
                    'SQS backend requires `composer require symfony/amazon-sqs-messenger`. ' .
                    'Not wired in v3.0; track in a follow-up.',
                );

            default:
                throw new RuntimeException(sprintf('Unknown backend type "%s".', $backendType));
        }
    }

    /**
     * @return array<class-string, string>
     */
    private static function parseConfigMap(string $xpath): array
    {
        $node = Mage::getConfig()->getNode($xpath);
        if (!$node) {
            return [];
        }
        $map = [];
        foreach ($node->children() as $messageClass => $valueNode) {
            $value = trim((string) $valueNode);
            if ($value !== '') {
                $map[(string) $messageClass] = $value;
            }
        }
        return $map;
    }
}
