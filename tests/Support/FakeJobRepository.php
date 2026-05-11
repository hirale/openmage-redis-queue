<?php

declare(strict_types=1);

namespace HiraleQueue\Tests\Support;

use Hirale_Queue_Model_Job;
use Hirale_Queue_Model_JobRepository;

class FakeJobRepository extends Hirale_Queue_Model_JobRepository
{
    /** @var array<string, array<string, mixed>> */
    public array $jobs = [];

    /**
     * @param list<array<string, mixed>> $jobs
     */
    public function __construct(array $jobs = [])
    {
        foreach ($jobs as $job) {
            $this->create($job);
        }
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): void
    {
        $jobId = (string) $data['job_id'];
        $this->jobs[$jobId] = array_merge([
            'job_id' => $jobId,
            'queue_name' => Hirale_Queue_Model_Job::DEFAULT_QUEUE,
            'handler' => '',
            'status' => Hirale_Queue_Model_Job::STATUS_QUEUED,
            'payload_json' => '{}',
            'metadata_json' => '{}',
            'attempt' => 0,
            'max_attempts' => 3,
            'retry_delay' => 60,
            'timeout' => 60,
            'available_at' => gmdate('Y-m-d H:i:s'),
            'created_at' => gmdate('Y-m-d H:i:s'),
            'updated_at' => gmdate('Y-m-d H:i:s'),
            'stream_id' => null,
            'last_error' => null,
            'finished_at' => null,
        ], $data);
    }

    public function loadByJobId(string $jobId): ?array
    {
        return $this->jobs[$jobId] ?? null;
    }

    public function loadByStreamId(string $streamId): ?array
    {
        foreach ($this->jobs as $job) {
            if (($job['stream_id'] ?? null) === $streamId) {
                return $job;
            }
        }

        return null;
    }

    public function listDueForPublish(int $limit): array
    {
        $jobs = [];
        foreach ($this->jobs as $job) {
            if (in_array($job['status'], [Hirale_Queue_Model_Job::STATUS_QUEUED, Hirale_Queue_Model_Job::STATUS_RETRY_WAIT], true)) {
                $jobs[] = $job;
            }
        }

        return array_slice($jobs, 0, $limit);
    }

    public function claimForPublish(array $job): bool
    {
        $jobId = (string) ($job['job_id'] ?? '');
        if (!isset($this->jobs[$jobId]) || !in_array($this->jobs[$jobId]['status'], [Hirale_Queue_Model_Job::STATUS_QUEUED, Hirale_Queue_Model_Job::STATUS_RETRY_WAIT], true)) {
            return false;
        }

        $this->jobs[$jobId]['status'] = Hirale_Queue_Model_Job::STATUS_PUBLISHING;

        return true;
    }

    public function releasePublishClaim(string $jobId, string $status): void
    {
        if (isset($this->jobs[$jobId]) && $this->jobs[$jobId]['status'] === Hirale_Queue_Model_Job::STATUS_PUBLISHING) {
            $this->jobs[$jobId]['status'] = $status;
        }
    }

    public function releaseStalePublishClaims(int $olderThanSeconds): int
    {
        $released = 0;
        foreach ($this->jobs as $jobId => $job) {
            if ($job['status'] === Hirale_Queue_Model_Job::STATUS_PUBLISHING) {
                $this->jobs[$jobId]['status'] = Hirale_Queue_Model_Job::STATUS_QUEUED;
                $this->jobs[$jobId]['stream_id'] = null;
                $released++;
            }
        }

        return $released;
    }

    public function markPublished(string $jobId, string $streamId, int $attempt): bool
    {
        if (!isset($this->jobs[$jobId]) || $this->jobs[$jobId]['status'] !== Hirale_Queue_Model_Job::STATUS_PUBLISHING) {
            return false;
        }

        $this->jobs[$jobId]['status'] = Hirale_Queue_Model_Job::STATUS_PUBLISHED;
        $this->jobs[$jobId]['stream_id'] = $streamId;
        $this->jobs[$jobId]['attempt'] = $attempt;

        return true;
    }

    public function markRunning(string $jobId, ?string $streamId = null): bool
    {
        if (!isset($this->jobs[$jobId]) || $this->jobs[$jobId]['status'] !== Hirale_Queue_Model_Job::STATUS_PUBLISHED) {
            return false;
        }
        if ($streamId !== null && $streamId !== '') {
            $currentStreamId = (string) ($this->jobs[$jobId]['stream_id'] ?? '');
            if ($currentStreamId !== '' && $currentStreamId !== $streamId) {
                return false;
            }
        }

        $this->jobs[$jobId]['status'] = Hirale_Queue_Model_Job::STATUS_RUNNING;

        return true;
    }

    public function markSucceeded(string $jobId): void
    {
        $this->jobs[$jobId]['status'] = Hirale_Queue_Model_Job::STATUS_SUCCEEDED;
        $this->jobs[$jobId]['payload_json'] = null;
        $this->jobs[$jobId]['stream_id'] = null;
    }

    public function scheduleRetry(string $jobId, string $lastError, int $retryDelay): void
    {
        $this->jobs[$jobId]['status'] = Hirale_Queue_Model_Job::STATUS_RETRY_WAIT;
        $this->jobs[$jobId]['stream_id'] = null;
        $this->jobs[$jobId]['last_error'] = $lastError;
        $this->jobs[$jobId]['retry_delay'] = $retryDelay;
    }

    public function markFailed(string $jobId, string $lastError): void
    {
        $this->jobs[$jobId]['status'] = Hirale_Queue_Model_Job::STATUS_FAILED;
        $this->jobs[$jobId]['stream_id'] = null;
        $this->jobs[$jobId]['last_error'] = $lastError;
    }

    public function retry(string $jobId): bool
    {
        if (!isset($this->jobs[$jobId]) || !in_array($this->jobs[$jobId]['status'], [Hirale_Queue_Model_Job::STATUS_FAILED, Hirale_Queue_Model_Job::STATUS_CANCELED], true)) {
            return false;
        }

        $this->jobs[$jobId]['status'] = Hirale_Queue_Model_Job::STATUS_QUEUED;
        $this->jobs[$jobId]['attempt'] = 0;
        $this->jobs[$jobId]['stream_id'] = null;
        $this->jobs[$jobId]['last_error'] = null;

        return true;
    }

    public function cancel(string $jobId): bool
    {
        if (!isset($this->jobs[$jobId]) || !in_array($this->jobs[$jobId]['status'], [
            Hirale_Queue_Model_Job::STATUS_QUEUED,
            Hirale_Queue_Model_Job::STATUS_RETRY_WAIT,
            Hirale_Queue_Model_Job::STATUS_PUBLISHING,
            Hirale_Queue_Model_Job::STATUS_PUBLISHED,
        ], true)) {
            return false;
        }

        $this->jobs[$jobId]['status'] = Hirale_Queue_Model_Job::STATUS_CANCELED;
        $this->jobs[$jobId]['stream_id'] = null;

        return true;
    }

    public function purgeFinished(int $olderThanDays): int
    {
        $deleted = 0;
        foreach ($this->jobs as $jobId => $job) {
            if (in_array($job['status'], [Hirale_Queue_Model_Job::STATUS_SUCCEEDED, Hirale_Queue_Model_Job::STATUS_FAILED, Hirale_Queue_Model_Job::STATUS_CANCELED], true)) {
                unset($this->jobs[$jobId]);
                $deleted++;
            }
        }

        return $deleted;
    }

    public function purgeByRetention(int $successDays, int $failureDays): int
    {
        return $this->purgeFinished(max($successDays, $failureDays));
    }

    public function createFromLegacyTask(array $task): string
    {
        $jobId = (string) ($task['job_id'] ?? $task['id'] ?? 'legacy-job');
        if (!isset($this->jobs[$jobId])) {
            $this->create([
                'job_id' => $jobId,
                'handler' => (string) ($task['handler'] ?? ''),
                'payload_json' => json_encode($task['data'] ?? []),
                'status' => Hirale_Queue_Model_Job::STATUS_PUBLISHED,
                'attempt' => (int) ($task['attempt'] ?? 1),
                'max_attempts' => (int) ($task['max_attempts'] ?? $task['retry_count'] ?? 3),
                'retry_delay' => (int) ($task['retry_delay'] ?? 60),
                'stream_id' => (string) ($task['stream_id'] ?? ''),
            ]);
        }

        return $jobId;
    }

    public function stats(): array
    {
        $stats = array_fill_keys(Hirale_Queue_Model_Job::statuses(), 0);
        foreach ($this->jobs as $job) {
            $stats[$job['status']]++;
        }

        return $stats;
    }
}
