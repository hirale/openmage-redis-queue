<?php

declare(strict_types=1);

namespace Hirale\Queue\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Snapshot of the retry policy captured at dispatch time from the dispatching
 * store's config. Travels with the envelope so a worker serving messages from
 * many stores never has to look up live config per message.
 */
final readonly class RetryPolicyStamp implements StampInterface
{
    public function __construct(
        public int $maxAttempts,
        public int $backoffBaseSeconds,
        public int $backoffCapSeconds,
    ) {
    }
}
