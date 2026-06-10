<?php

declare(strict_types=1);

namespace Hirale\Queue\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * The dispatching store id, captured at dispatch time. The consumer never
 * consults live config — store-scoped policy was already baked into
 * RetryPolicyStamp when the envelope was created.
 */
final readonly class StoreScopeStamp implements StampInterface
{
    public function __construct(public ?int $storeId)
    {
    }
}
