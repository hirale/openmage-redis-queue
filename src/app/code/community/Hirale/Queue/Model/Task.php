<?php

declare(strict_types=1);

use Monolog\Level;
use Predis\Client;
use Predis\Response\ServerException;

class Hirale_Queue_Model_Task
{
    private ?Client $_redis = null;
    private ?int $_count = null;
    private ?string $_streamKey = null;
    private ?string $_group = null;
    private ?string $_consumer = null;

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
            $task = [
                'id' => bin2hex(random_bytes(16)),
                'handler' => $handler,
                'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'retry_count' => (string) $retryCount,
                'retry_delay' => (string) $retryDelay,
                'timeout' => (string) $timeout,
            ];

            $this->_xadd($task);
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
        $this->_ensureGroup();

        $tasks = $this->_readGroup('0', 1);
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
        $helper = Mage::helper('hirale_queue');
        if (!$helper instanceof Hirale_Queue_Helper_Data || !$helper->getConfigFlag('enabled')) {
            return;
        }

        try {
            $tasks = $this->fetchTasks();
            if (empty($tasks)) {
                return;
            }

            foreach ($tasks as $task) {
                $this->processTask($task);
            }
        } catch (Throwable $e) {
            Mage::logException($e);
        }
    }

    /**
     * Execute a single stream message and acknowledge it on success.
     *
     * Failed tasks are handled by _handleFailure(), which either requeues the
     * task with one fewer retry or acknowledges the exhausted message.
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

            $handler->handle($task);
            $this->_ackTask((string) $task['stream_id']);
        } catch (Throwable $e) {
            $this->_handleFailure($task, $e);
        }
    }

    /**
     * Requeue failed work until retry_count is exhausted.
     *
     * Redis Streams do not provide delayed retries here; retry_delay is retained
     * in the payload for compatibility with existing callers and future workers.
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

        $retryCount = max(0, (int) ($task['retry_count'] ?? 0) - 1);
        if ($retryCount > 0) {
            $retryTask = $task;
            unset($retryTask['stream_id']);
            $retryTask['retry_count'] = (string) $retryCount;
            $retryTask['last_error'] = $e->getMessage();
            $this->_xadd($retryTask);
        } else {
            Mage::log(
                sprintf('Task exhausted retries: %s', print_r($task, true)),
                Level::Error,
                'exception.log',
            );
        }

        $this->_ackTask((string) $task['stream_id']);
    }

    /**
     * Append one normalized task payload to the configured Redis stream.
     *
     * @param array<string, mixed> $task
     */
    private function _xadd(array $task): void
    {
        $command = ['XADD', $this->_getStreamKey(), '*'];
        foreach ($task as $field => $value) {
            $command[] = (string) $field;
            $command[] = is_scalar($value) || $value === null
                ? (string) $value
                : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        $this->_getRedis()->executeRaw($command);
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
                $payload['data'] = $this->_decodePayloadData($payload['data'] ?? null);
                $tasks[] = $payload;
            }
        }

        return $tasks;
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
}
