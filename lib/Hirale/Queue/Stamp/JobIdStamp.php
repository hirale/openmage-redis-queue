<?php

declare(strict_types=1);

namespace Hirale\Queue\Stamp;

use Symfony\Component\Messenger\Stamp\StampInterface;

/**
 * Carries the stable hirale_queue_job.job_id alongside the envelope so the
 * consumer-side worker listeners can locate the DB row regardless of which
 * transport-specific id the message carried.
 */
final readonly class JobIdStamp implements StampInterface
{
    public function __construct(public string $jobId)
    {
    }
}
