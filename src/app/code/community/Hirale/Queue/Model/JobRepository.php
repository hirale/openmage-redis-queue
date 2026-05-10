<?php

declare(strict_types=1);

class Hirale_Queue_Model_JobRepository
{
    private const TABLE_ALIAS = 'hirale_queue/job';

    /** @var object|null */
    private $_connection = null;
    private ?string $_table = null;

    /**
     * @param object $connection
     */
    public function setConnection($connection, string $table): self
    {
        $this->_connection = $connection;
        $this->_table = $table;

        return $this;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): void
    {
        $now = $this->_now();
        $row = array_merge([
            'status' => Hirale_Queue_Model_Job::STATUS_QUEUED,
            'queue_name' => Hirale_Queue_Model_Job::DEFAULT_QUEUE,
            'attempt' => 0,
            'max_attempts' => Hirale_Queue_Model_Job::DEFAULT_MAX_ATTEMPTS,
            'retry_delay' => Hirale_Queue_Model_Job::DEFAULT_RETRY_DELAY,
            'timeout' => Hirale_Queue_Model_Job::DEFAULT_TIMEOUT,
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ], $data);

        $this->_getConnection()->insert($this->_getTable(), $row);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadByJobId(string $jobId): ?array
    {
        $row = $this->_fetchRow(sprintf(
            'SELECT * FROM %s WHERE job_id = %s LIMIT 1',
            $this->_getTable(),
            $this->_quote($jobId),
        ));

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadByStreamId(string $streamId): ?array
    {
        $row = $this->_fetchRow(sprintf(
            'SELECT * FROM %s WHERE stream_id = %s LIMIT 1',
            $this->_getTable(),
            $this->_quote($streamId),
        ));

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listDueForPublish(int $limit): array
    {
        return $this->_fetchAll(sprintf(
            "SELECT * FROM %s WHERE status IN ('%s','%s') AND available_at <= %s ORDER BY available_at ASC, entity_id ASC LIMIT %d",
            $this->_getTable(),
            Hirale_Queue_Model_Job::STATUS_QUEUED,
            Hirale_Queue_Model_Job::STATUS_RETRY_WAIT,
            $this->_quote($this->_now()),
            max(1, $limit),
        ));
    }

    public function markPublished(string $jobId, string $streamId, int $attempt): void
    {
        $this->_updateByJobId($jobId, [
            'status' => Hirale_Queue_Model_Job::STATUS_PUBLISHED,
            'stream_id' => $streamId,
            'attempt' => $attempt,
            'updated_at' => $this->_now(),
        ]);
    }

    public function markRunning(string $jobId): void
    {
        $this->_updateByJobId($jobId, [
            'status' => Hirale_Queue_Model_Job::STATUS_RUNNING,
            'updated_at' => $this->_now(),
        ]);
    }

    public function markSucceeded(string $jobId): void
    {
        $now = $this->_now();
        $this->_updateByJobId($jobId, [
            'status' => Hirale_Queue_Model_Job::STATUS_SUCCEEDED,
            'payload_json' => null,
            'last_error' => null,
            'stream_id' => null,
            'finished_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function scheduleRetry(string $jobId, string $lastError, int $retryDelay): void
    {
        $this->_updateByJobId($jobId, [
            'status' => Hirale_Queue_Model_Job::STATUS_RETRY_WAIT,
            'stream_id' => null,
            'last_error' => $lastError,
            'available_at' => $this->_date(time() + max(0, $retryDelay)),
            'updated_at' => $this->_now(),
        ]);
    }

    public function markFailed(string $jobId, string $lastError): void
    {
        $now = $this->_now();
        $this->_updateByJobId($jobId, [
            'status' => Hirale_Queue_Model_Job::STATUS_FAILED,
            'stream_id' => null,
            'last_error' => $lastError,
            'finished_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function retry(string $jobId): void
    {
        $this->_updateByJobId($jobId, [
            'status' => Hirale_Queue_Model_Job::STATUS_QUEUED,
            'attempt' => 0,
            'stream_id' => null,
            'last_error' => null,
            'available_at' => $this->_now(),
            'finished_at' => null,
            'updated_at' => $this->_now(),
        ]);
    }

    public function cancel(string $jobId): void
    {
        $now = $this->_now();
        $this->_updateByJobId($jobId, [
            'status' => Hirale_Queue_Model_Job::STATUS_CANCELED,
            'stream_id' => null,
            'finished_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function purgeFinished(int $olderThanDays): int
    {
        $threshold = $this->_date(time() - max(0, $olderThanDays) * 86400);

        return $this->_delete(sprintf(
            "status IN ('%s','%s','%s') AND updated_at < %s",
            Hirale_Queue_Model_Job::STATUS_SUCCEEDED,
            Hirale_Queue_Model_Job::STATUS_FAILED,
            Hirale_Queue_Model_Job::STATUS_CANCELED,
            $this->_quote($threshold),
        ));
    }

    public function purgeByRetention(int $successDays, int $failureDays): int
    {
        $successThreshold = $this->_date(time() - max(0, $successDays) * 86400);
        $failureThreshold = $this->_date(time() - max(0, $failureDays) * 86400);

        $deleted = $this->_delete(sprintf(
            "status = '%s' AND updated_at < %s",
            Hirale_Queue_Model_Job::STATUS_SUCCEEDED,
            $this->_quote($successThreshold),
        ));
        $deleted += $this->_delete(sprintf(
            "status IN ('%s','%s') AND updated_at < %s",
            Hirale_Queue_Model_Job::STATUS_FAILED,
            Hirale_Queue_Model_Job::STATUS_CANCELED,
            $this->_quote($failureThreshold),
        ));

        return $deleted;
    }

    /**
     * @param array<string, mixed> $task
     */
    public function createFromLegacyTask(array $task): string
    {
        $jobId = (string) ($task['job_id'] ?? $task['id'] ?? bin2hex(random_bytes(16)));
        if ($this->loadByJobId($jobId) !== null) {
            return $jobId;
        }

        $payload = $task['data'] ?? [];
        $this->create([
            'job_id' => $jobId,
            'queue_name' => (string) ($task['queue'] ?? Hirale_Queue_Model_Job::DEFAULT_QUEUE),
            'handler' => (string) ($task['handler'] ?? ''),
            'status' => Hirale_Queue_Model_Job::STATUS_PUBLISHED,
            'payload_json' => $this->_encode($payload),
            'metadata_json' => $this->_encode(['legacy_stream_message' => true]),
            'attempt' => 1,
            'max_attempts' => max(1, (int) ($task['retry_count'] ?? Hirale_Queue_Model_Job::DEFAULT_MAX_ATTEMPTS)),
            'retry_delay' => max(0, (int) ($task['retry_delay'] ?? Hirale_Queue_Model_Job::DEFAULT_RETRY_DELAY)),
            'timeout' => max(1, (int) ($task['timeout'] ?? Hirale_Queue_Model_Job::DEFAULT_TIMEOUT)),
            'stream_id' => (string) ($task['stream_id'] ?? ''),
        ]);

        return $jobId;
    }

    /**
     * @param array<string, mixed> $filters
     * @return list<array<string, mixed>>
     */
    public function search(array $filters = [], int $limit = 100): array
    {
        $where = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = ' . $this->_quote((string) $filters['status']);
        }
        if (!empty($filters['handler'])) {
            $where[] = 'handler LIKE ' . $this->_quote('%' . (string) $filters['handler'] . '%');
        }
        if (!empty($filters['queue'])) {
            $where[] = 'queue_name = ' . $this->_quote((string) $filters['queue']);
        }

        return $this->_fetchAll(sprintf(
            'SELECT * FROM %s%s ORDER BY entity_id DESC LIMIT %d',
            $this->_getTable(),
            $where === [] ? '' : ' WHERE ' . implode(' AND ', $where),
            max(1, $limit),
        ));
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        $stats = array_fill_keys(Hirale_Queue_Model_Job::statuses(), 0);
        foreach ($this->_fetchAll(sprintf('SELECT status, COUNT(*) AS total FROM %s GROUP BY status', $this->_getTable())) as $row) {
            $status = (string) ($row['status'] ?? '');
            if ($status !== '') {
                $stats[$status] = (int) ($row['total'] ?? 0);
            }
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $values
     */
    private function _updateByJobId(string $jobId, array $values): void
    {
        $this->_getConnection()->update($this->_getTable(), $values, 'job_id = ' . $this->_quote($jobId));
    }

    private function _delete(string $where): int
    {
        $result = $this->_getConnection()->delete($this->_getTable(), $where);

        return is_int($result) ? $result : 0;
    }

    /**
     * @return array<string, mixed>|false|null
     */
    private function _fetchRow(string $sql)
    {
        $connection = $this->_getConnection();
        if (method_exists($connection, 'fetchRow')) {
            return $connection->fetchRow($sql);
        }

        $rows = $connection->fetchAll($sql);
        return $rows[0] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function _fetchAll(string $sql): array
    {
        $rows = $this->_getConnection()->fetchAll($sql);

        return is_array($rows) ? $rows : [];
    }

    private function _quote(string $value): string
    {
        return $this->_getConnection()->quote($value);
    }

    private function _getConnection(): object
    {
        if ($this->_connection !== null) {
            return $this->_connection;
        }

        $resource = Mage::getSingleton('core/resource');
        if (!is_object($resource) || !method_exists($resource, 'getConnection')) {
            throw new RuntimeException('Hirale Queue database resource is unavailable.');
        }

        $this->_connection = $resource->getConnection('core_write');
        return $this->_connection;
    }

    private function _getTable(): string
    {
        if ($this->_table !== null) {
            return $this->_table;
        }

        $resource = Mage::getSingleton('core/resource');
        if (is_object($resource) && method_exists($resource, 'getTableName')) {
            $this->_table = $resource->getTableName(self::TABLE_ALIAS);
        } else {
            $this->_table = 'hirale_queue_job';
        }

        return $this->_table;
    }

    /**
     * @param mixed $value
     */
    private function _encode($value): string
    {
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : '{}';
    }

    private function _now(): string
    {
        return $this->_date(time());
    }

    private function _date(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
