<?php

declare(strict_types=1);

namespace Hirale\Queue\Exception;

/**
 * Thrown at dispatch when the serialized message payload exceeds the
 * hirale_queue/operational/payload_max_bytes limit. Producers catch this to
 * fail fast — payloads are never truncated, because the stored JSON must stay
 * faithful for retry reconstruction.
 */
final class PayloadTooLargeException extends \RuntimeException
{
}
