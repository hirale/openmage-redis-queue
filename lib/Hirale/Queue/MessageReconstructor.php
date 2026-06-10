<?php

declare(strict_types=1);

namespace Hirale\Queue;

use ReflectionClass;
use RuntimeException;

/**
 * Rebuilds a message object from the payload_json snapshot stored in
 * hirale_queue_job. Shared by the admin Retry action and the
 * `hirale:queue:retry-failed` CLI.
 *
 * Strategy: constructor with named args first (the common case — typed
 * constructor-property-promoted message classes), reflection fallback for
 * payloads whose keys no longer line up with the constructor.
 */
final class MessageReconstructor
{
    /**
     * @param array<string, mixed> $payload
     */
    public static function reconstruct(string $messageClass, array $payload): object
    {
        if (!class_exists($messageClass)) {
            throw new RuntimeException(sprintf('Message class "%s" not found.', $messageClass));
        }
        try {
            return new $messageClass(...$payload);
        } catch (\Throwable) {
            $rc = new ReflectionClass($messageClass);
            $object = $rc->newInstanceWithoutConstructor();
            foreach ($payload as $key => $value) {
                if (!is_string($key) || !$rc->hasProperty($key)) {
                    continue;
                }
                $rc->getProperty($key)->setValue($object, $value);
            }
            return $object;
        }
    }
}
