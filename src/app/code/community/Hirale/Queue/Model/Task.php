<?php

declare(strict_types=1);

use Monolog\Level;
use Predis\Client;
use Predis\Response\ServerException;

class Hirale_Queue_Model_Task
{
    private ?Client $_redis = null;
    private ?int $_count = null;
    private ?int $_publishLimit = null;
    private ?string $_streamKey = null;
    private ?string $_group = null;
    private ?string $_consumer = null;
    private ?Hirale_Queue_Model_Queue $_queue = null;

    /**
     * Add a task to the Redis stream.
     *
     * $handler must be a Mage model alias whose model implements
     * Hirale_Queue_Model_TaskHandlerInterface. Storing aliases instead of class
     * names keeps local rewrites and community/local overrides available.
     *
     * @param mixed $data
     */
    public function addTask(string $handler, $data, int $retryCount = 3, int $retryDelay = 60, int $timeout = 60): void
    {
        try {
            $this->_getQueueService()->enqueuePayload($handler, $data, [
                'max_attempts' => $retryCount,
                'retry_delay' => $retryDelay,
                'timeout' => $timeout,
            ]);
        } catch (Throwable $e) {
            Mage::log($e->getMessage(), Level::Error, 'exception.log');
        }
    }

    /**
     * Fetch pending work for this consumer.
     *
     * The consumer first claims one pending message for retry/recovery, then
     * blocks briefly for new stream messages. A null return means there is no
     * work available for this cron tick.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function fetchTasks(): ?array
    {
        $this->_getQueueService()->publishDueJobs($this->_getPublishLimit());
        $this->_ensureGroup();

        $tasks = $this->_claimPendingTasks();
        if ($tasks === []) {
            $tasks = $this->_readGroup('>', $this->_getCount(), 5000);
        }

        return $tasks === [] ? null : $tasks;
    }

    /**
     * Cron entry point for processing queued tasks.
     *
     * The admin config flag gates Redis access so installations can ship the
     * module disabled and enable workers only after Redis is configured.
     */
    public function process(): void
    {
        try {
            $this->processBatch();
        } catch (Throwable $e) {
            Mage::logException($e);
        }
    }

    /**
     * Process one worker batch.
     *
     * @return int Number of tasks processed in this batch.
     */
    public function processBatch(): int
    {
        $helper = Mage::helper('hirale_queue');
        if (!$helper instanceof Hirale_Queue_Helper_Data || !$helper->getConfigFlag('enabled')) {
            return 0;
        }

        $tasks = $this->fetchTasks();
        if (empty($tasks)) {
            return 0;
        }

        foreach ($tasks as $task) {
            $this->processTask($task);
        }

        return count($tasks);
    }

    public function setConsumer(string $consumer): self
    {
        $consumer = trim($consumer);
        $this->_consumer = $consumer !== '' ? $consumer : 'hirale_queue_worker';

        return $this;
    }

    public function setCount(int $count): self
    {
        $this->_count = max(1, $count);

        return $this;
    }

    public function setPublishLimit(int $publishLimit): self
    {
        $this->_publishLimit = max(1, $publishLimit);

        return $this;
    }

    /**
     * Execute a single stream message and acknowledge it on success.
     *
     * Failed tasks are handled by _handleFailure(), which either schedules a
     * DB-backed retry or marks the job failed before acknowledging the stream
     * message.
     *
     * @param array<string, mixed> $task
     */
    public function processTask(array $task): void
    {
        if (empty($task['stream_id']) || empty($task['handler'])) {
            return;
        }

        try {
            $handler = Mage::getModel((string) $task['handler']);
            if (!$handler instanceof Hirale_Queue_Model_TaskHandlerInterface) {
                throw new RuntimeException(sprintf(
                    'Queue handler "%s" must implement Hirale_Queue_Model_TaskHandlerInterface.',
                    (string) $task['handler'],
                ));
            }

            $jobId = $this->_getQueueService()->markRunning($task);
            $handler->handle($task);
            $this->_getQueueService()->markSucceeded($jobId);
            $this->_ackTask((string) $task['stream_id']);
        } catch (Throwable $e) {
            $this->_handleFailure($task, $e);
        }
    }

    /**
     * Schedule failed work for later publishing until max attempts are exhausted.
     *
     * @param array<string, mixed> $task
     */
    private function _handleFailure(array $task, Throwable $e): void
    {
        Mage::log(
            sprintf('Failed to process task %s: %s', (string) ($task['handler'] ?? ''), $e->getMessage()),
            Level::Error,
            'exception.log',
        );

        $jobId = $this->_getQueueService()->ensureJobForTask($task);
        $attempt = max(1, (int) ($task['attempt'] ?? 1));
        $maxAttempts = max(1, (int) ($task['max_attempts'] ?? $task['retry_count'] ?? 1));
        if ($attempt < $maxAttempts) {
            $this->_getQueueService()->scheduleRetry(
                $jobId,
                $e->getMessage(),
                max(0, (int) ($task['retry_delay'] ?? Hirale_Queue_Model_Job::DEFAULT_RETRY_DELAY)),
            );
        } else {
            $this->_getQueueService()->markFailed($jobId, $e->getMessage());
            Mage::log(
                sprintf('Task exhausted retries: %s', print_r($task, true)),
                Level::Error,
                'exception.log',
            );
        }

        $this->_ackTask((string) $task['stream_id']);
    }

    /**
     * Read messages from the consumer group and normalize Redis' nested stream
     * response into associative task arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    private function _readGroup(string $id, int $count, int $block = 0): array
    {
        $command = [
            'XREADGROUP',
            'GROUP',
            $this->_getGroup(),
            $this->_getConsumer(),
            'COUNT',
            (string) $count,
        ];

        if ($block > 0) {
            $command[] = 'BLOCK';
            $command[] = (string) $block;
        }

        array_push($command, 'STREAMS', $this->_getStreamKey(), $id);
        $results = $this->_getRedis()->executeRaw($command);
        if (empty($results) || !is_array($results)) {
            return [];
        }

        $tasks = [];
        foreach ($results as $streamData) {
            if (!is_array($streamData) || empty($streamData[1]) || !is_array($streamData[1])) {
                continue;
            }

            foreach ($streamData[1] as $message) {
                if (!is_array($message) || empty($message[0]) || empty($message[1])) {
                    continue;
                }

                $payload = $this->_payloadToAssoc($message[1]);
                $payload['stream_id'] = (string) $message[0];
                $tasks[] = $this->_normalizeTaskPayload($payload);
            }
        }

        return $tasks;
    }

    /**
     * Recover stale pending messages before reading new work.
     *
     * Redis 6.2+ supports XAUTOCLAIM. Older Redis servers fall back to reading
     * this consumer's pending messages with XREADGROUP 0, preserving 1.x
     * behavior on Redis 5.x.
     *
     * @return array<int, array<string, mixed>>
     */
    private function _claimPendingTasks(): array
    {
        try {
            $results = $this->_getRedis()->executeRaw([
                'XAUTOCLAIM',
                $this->_getStreamKey(),
                $this->_getGroup(),
                $this->_getConsumer(),
                (string) ($this->_getPendingIdleSeconds() * 1000),
                '0-0',
                'COUNT',
                (string) $this->_getCount(),
            ]);

            if (empty($results) || !is_array($results) || empty($results[1]) || !is_array($results[1])) {
                return [];
            }

            $tasks = [];
            foreach ($results[1] as $message) {
                if (!is_array($message) || empty($message[0]) || empty($message[1])) {
                    continue;
                }

                $payload = $this->_payloadToAssoc($message[1]);
                $payload['stream_id'] = (string) $message[0];
                $tasks[] = $this->_normalizeTaskPayload($payload);
            }

            return $tasks;
        } catch (ServerException $e) {
            $message = strtolower($e->getMessage());
            if (!str_contains($message, 'unknown') && !str_contains($message, 'syntax')) {
                throw $e;
            }
        }

        return $this->_readGroup('0', 1);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function _normalizeTaskPayload(array $payload): array
    {
        $payload['data'] = $this->_decodePayloadData($payload['data'] ?? null);
        $payload['metadata'] = $this->_decodePayloadData($payload['metadata'] ?? null);
        if (!isset($payload['max_attempts']) && isset($payload['retry_count'])) {
            $payload['max_attempts'] = $payload['retry_count'];
        }
        if (!isset($payload['attempt'])) {
            $payload['attempt'] = '1';
        }

        return $payload;
    }

    private function _ackTask(string $streamId): void
    {
        $this->_getRedis()->executeRaw(['XACK', $this->_getStreamKey(), $this->_getGroup(), $streamId]);
        $this->_getRedis()->executeRaw(['XDEL', $this->_getStreamKey(), $streamId]);
    }

    /**
     * Create the consumer group once, ignoring Redis' BUSYGROUP response when
     * another worker has already created it.
     */
    private function _ensureGroup(): void
    {
        try {
            $this->_getRedis()->executeRaw(['XGROUP', 'CREATE', $this->_getStreamKey(), $this->_getGroup(), '0', 'MKSTREAM']);
        } catch (ServerException $e) {
            if (!str_contains($e->getMessage(), 'BUSYGROUP')) {
                throw $e;
            }
        }
    }

    /**
     * Convert Redis' alternating field/value payload into an associative array.
     *
     * @param mixed $payload
     * @return array<string, mixed>
     */
    private function _payloadToAssoc($payload): array
    {
        if (!is_array($payload)) {
            return [];
        }

        $keys = array_keys($payload);
        if ($keys !== range(0, count($payload) - 1)) {
            return $payload;
        }

        $data = [];
        for ($i = 0, $count = count($payload); $i < $count; $i += 2) {
            if (!isset($payload[$i])) {
                continue;
            }
            $data[(string) $payload[$i]] = $payload[$i + 1] ?? null;
        }

        return $data;
    }

    /**
     * @param mixed $value
     * @return mixed
     */
    private function _decodePayloadData($value)
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : [];
    }

    private function _getCount(): int
    {
        if ($this->_count === null) {
            $this->_count = max(1, (int) ($this->_getQueueHelper()->getConfigValue('count', 10) ?: 10));
        }

        return $this->_count;
    }

    private function _getPublishLimit(): int
    {
        if ($this->_publishLimit === null) {
            $this->_publishLimit = max(1, (int) ($this->_getQueueHelper()->getConfigValue('publish_limit', 100) ?: 100));
        }

        return $this->_publishLimit;
    }

    private function _getPendingIdleSeconds(): int
    {
        return max(1, (int) ($this->_getQueueHelper()->getConfigValue('pending_idle_seconds', 300) ?: 300));
    }

    private function _getStreamKey(): string
    {
        if ($this->_streamKey === null) {
            $this->_streamKey = trim((string) $this->_getQueueHelper()->getConfigValue('stream_key', 'hirale_queue_stream'));
            if ($this->_streamKey === '') {
                $this->_streamKey = 'hirale_queue_stream';
            }
        }

        return $this->_streamKey;
    }

    private function _getGroup(): string
    {
        if ($this->_group === null) {
            $this->_group = trim((string) $this->_getQueueHelper()->getConfigValue('group', 'hirale_queue'));
            if ($this->_group === '') {
                $this->_group = 'hirale_queue';
            }
        }

        return $this->_group;
    }

    private function _getConsumer(): string
    {
        if ($this->_consumer === null) {
            $this->_consumer = trim((string) $this->_getQueueHelper()->getConfigValue('consumer', 'hirale_queue_worker'));
            if ($this->_consumer === '') {
                $this->_consumer = 'hirale_queue_worker';
            }
        }

        return $this->_consumer;
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

    private function _getQueueHelper(): Hirale_Queue_Helper_Data
    {
        $helper = Mage::helper('hirale_queue');
        if (!$helper instanceof Hirale_Queue_Helper_Data) {
            throw new RuntimeException('Hirale Queue helper is unavailable.');
        }

        return $helper;
    }

    private function _getQueueService(): Hirale_Queue_Model_Queue
    {
        if ($this->_queue === null) {
            $queue = Mage::getModel('hirale_queue/queue');
            if (!$queue instanceof Hirale_Queue_Model_Queue) {
                $queue = new Hirale_Queue_Model_Queue();
            }
            $this->_queue = $queue;
        }

        return $this->_queue;
    }
}
