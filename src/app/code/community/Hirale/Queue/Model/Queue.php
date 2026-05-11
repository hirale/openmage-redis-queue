<?php

declare(strict_types=1);

use Monolog\Level;
use Predis\Client;

class Hirale_Queue_Model_Queue
{
    private ?Hirale_Queue_Model_JobRepository $_repository = null;
    private ?Client $_redis = null;
    private ?string $_streamKey = null;

    public function setRepository(Hirale_Queue_Model_JobRepository $repository): self
    {
        $this->_repository = $repository;

        return $this;
    }

    public function setRedis(Client $redis): self
    {
        $this->_redis = $redis;

        return $this;
    }

    /**
     * Enqueue work for an async queue handler.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $options
     */
    public function enqueue(string $handler, array $payload = [], array $options = []): string
    {
        return $this->enqueuePayload($handler, $payload, $options);
    }

    /**
     * Compatibility entry point for the legacy addTask() API, which accepted
     * any JSON-serializable payload.
     *
     * @param mixed $payload
     * @param array<string, mixed> $options
     */
    public function enqueuePayload(string $handler, $payload = [], array $options = []): string
    {
        $jobId = (string) ($options['job_id'] ?? bin2hex(random_bytes(16)));
        $delay = max(0, (int) ($options['delay'] ?? 0));
        $now = time();
        $availableAt = gmdate('Y-m-d H:i:s', $now + $delay);

        $this->_getRepository()->create([
            'job_id' => $jobId,
            'queue_name' => $this->_cleanString((string) ($options['queue'] ?? Hirale_Queue_Model_Job::DEFAULT_QUEUE), Hirale_Queue_Model_Job::DEFAULT_QUEUE),
            'handler' => $handler,
            'status' => Hirale_Queue_Model_Job::STATUS_QUEUED,
            'payload_json' => $this->_encode($payload),
            'metadata_json' => $this->_encode($options['metadata'] ?? []),
            'max_attempts' => max(1, (int) ($options['max_attempts'] ?? Hirale_Queue_Model_Job::DEFAULT_MAX_ATTEMPTS)),
            'retry_delay' => max(0, (int) ($options['retry_delay'] ?? Hirale_Queue_Model_Job::DEFAULT_RETRY_DELAY)),
            'timeout' => max(1, (int) ($options['timeout'] ?? Hirale_Queue_Model_Job::DEFAULT_TIMEOUT)),
            'available_at' => $availableAt,
        ]);

        if ($delay === 0) {
            $this->publishDueJobs(1);
        }

        return $jobId;
    }

    public function publishDueJobs(int $limit = 100): int
    {
        $this->_getRepository()->releaseStalePublishClaims(300);

        $published = 0;
        foreach ($this->_getRepository()->listDueForPublish(max(1, $limit)) as $job) {
            if (!$this->_getRepository()->claimForPublish($job)) {
                continue;
            }

            $jobId = (string) $job['job_id'];
            $previousStatus = (string) $job['status'];
            try {
                $streamId = $this->_publishJob($job);
                if ($streamId === '') {
                    $this->_getRepository()->releasePublishClaim($jobId, $previousStatus);
                    continue;
                }

                if (!$this->_getRepository()->markPublished(
                    $jobId,
                    $streamId,
                    (int) ($job['attempt'] ?? 0) + 1,
                )) {
                    continue;
                }

                $published++;
            } catch (Throwable $e) {
                $this->_getRepository()->releasePublishClaim($jobId, $previousStatus);
                Mage::log($e->getMessage(), Level::Error, 'exception.log');
                break;
            }
        }

        return $published;
    }

    /**
     * @param array<string, mixed> $task
     */
    public function ensureJobForTask(array $task): string
    {
        $jobId = (string) ($task['job_id'] ?? '');
        if ($jobId !== '' && $this->_getRepository()->loadByJobId($jobId) !== null) {
            return $jobId;
        }

        if ($jobId !== '') {
            $task['id'] = $jobId;
        }

        return $this->_getRepository()->createFromLegacyTask($task);
    }

    /**
     * @param array<string, mixed> $task
     */
    public function markRunning(array $task): string
    {
        $jobId = $this->ensureJobForTask($task);
        if (!$this->_getRepository()->markRunning($jobId, (string) ($task['stream_id'] ?? ''))) {
            return '';
        }

        return $jobId;
    }

    /**
     * @param array<string, mixed> $task
     */
    public function shouldAcknowledgeSkippedTask(array $task): bool
    {
        $jobId = (string) ($task['job_id'] ?? $task['id'] ?? '');
        if ($jobId === '') {
            return false;
        }

        $job = $this->_getRepository()->loadByJobId($jobId);
        if ($job === null) {
            return false;
        }

        $status = (string) ($job['status'] ?? '');
        if (in_array($status, [
            Hirale_Queue_Model_Job::STATUS_QUEUED,
            Hirale_Queue_Model_Job::STATUS_RETRY_WAIT,
            Hirale_Queue_Model_Job::STATUS_CANCELED,
            Hirale_Queue_Model_Job::STATUS_SUCCEEDED,
            Hirale_Queue_Model_Job::STATUS_FAILED,
            Hirale_Queue_Model_Job::STATUS_RUNNING,
        ], true)) {
            return true;
        }

        $streamId = (string) ($task['stream_id'] ?? '');
        $currentStreamId = (string) ($job['stream_id'] ?? '');

        return $status === Hirale_Queue_Model_Job::STATUS_PUBLISHED
            && $streamId !== ''
            && $currentStreamId !== ''
            && $currentStreamId !== $streamId;
    }

    public function markSucceeded(string $jobId): void
    {
        $this->_getRepository()->markSucceeded($jobId);
    }

    public function markFailed(string $jobId, string $lastError): void
    {
        $this->_getRepository()->markFailed($jobId, $lastError);
    }

    public function scheduleRetry(string $jobId, string $lastError, int $retryDelay): void
    {
        $this->_getRepository()->scheduleRetry($jobId, $lastError, $retryDelay);
    }

    /**
     * @return array<string, int>
     */
    public function getStats(): array
    {
        return $this->_getRepository()->stats();
    }

    public function retry(string $jobId): void
    {
        if (!$this->_getRepository()->retry($jobId)) {
            throw new RuntimeException('Queue job cannot be retried in its current state.');
        }

        $this->publishDueJobs(1);
    }

    public function cancel(string $jobId): void
    {
        if (!$this->_getRepository()->cancel($jobId)) {
            throw new RuntimeException('Queue job cannot be canceled in its current state.');
        }
    }

    public function purgeFinished(int $olderThanDays): int
    {
        return $this->_getRepository()->purgeFinished($olderThanDays);
    }

    public function purgeByRetention(int $successDays, int $failureDays): int
    {
        return $this->_getRepository()->purgeByRetention($successDays, $failureDays);
    }

    public function testRedisConnection(): bool
    {
        $response = $this->_getRedis()->executeRaw(['PING']);

        return $response === 'PONG' || $response === true || (is_object($response) && (string) $response === 'PONG');
    }

    /**
     * @param array<string, mixed> $job
     */
    private function _publishJob(array $job): string
    {
        $payload = [
            'job_id' => (string) $job['job_id'],
            'handler' => (string) $job['handler'],
            'queue' => (string) ($job['queue_name'] ?? Hirale_Queue_Model_Job::DEFAULT_QUEUE),
            'data' => (string) ($job['payload_json'] ?? '{}'),
            'metadata' => (string) ($job['metadata_json'] ?? '{}'),
            'attempt' => (string) ((int) ($job['attempt'] ?? 0) + 1),
            'max_attempts' => (string) max(1, (int) ($job['max_attempts'] ?? Hirale_Queue_Model_Job::DEFAULT_MAX_ATTEMPTS)),
            'retry_delay' => (string) max(0, (int) ($job['retry_delay'] ?? Hirale_Queue_Model_Job::DEFAULT_RETRY_DELAY)),
            'timeout' => (string) max(1, (int) ($job['timeout'] ?? Hirale_Queue_Model_Job::DEFAULT_TIMEOUT)),
        ];

        $command = ['XADD', $this->_getStreamKey(), '*'];
        foreach ($payload as $field => $value) {
            $command[] = $field;
            $command[] = $value;
        }

        $streamId = $this->_getRedis()->executeRaw($command);

        return is_scalar($streamId) ? (string) $streamId : '';
    }

    private function _getRepository(): Hirale_Queue_Model_JobRepository
    {
        if ($this->_repository === null) {
            $repository = Mage::getModel('hirale_queue/jobRepository');
            if (!$repository instanceof Hirale_Queue_Model_JobRepository) {
                $repository = new Hirale_Queue_Model_JobRepository();
            }
            $this->_repository = $repository;
        }

        return $this->_repository;
    }

    private function _getRedis(): Client
    {
        if ($this->_redis === null) {
            $redis = $this->_getQueueHelper()->getRedis();
            if (!$redis instanceof Client) {
                throw new RuntimeException('Hirale Queue Redis client is unavailable.');
            }
            $this->_redis = $redis;
        }

        return $this->_redis;
    }

    private function _getStreamKey(): string
    {
        if ($this->_streamKey === null) {
            $this->_streamKey = $this->_cleanString(
                (string) $this->_getQueueHelper()->getConfigValue('stream_key', 'hirale_queue_stream'),
                'hirale_queue_stream',
            );
        }

        return $this->_streamKey;
    }

    private function _getQueueHelper(): Hirale_Queue_Helper_Data
    {
        $helper = Mage::helper('hirale_queue');
        if (!$helper instanceof Hirale_Queue_Helper_Data) {
            throw new RuntimeException('Hirale Queue helper is unavailable.');
        }

        return $helper;
    }

    private function _cleanString(string $value, string $default): string
    {
        $value = trim($value);

        return $value === '' ? $default : $value;
    }

    /**
     * @param mixed $value
     */
    private function _encode($value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
