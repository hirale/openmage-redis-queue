<?php

/**
 * Service layer over hirale_queue_job + hirale_queue_job_event. All write paths
 * (state transitions, attempt increments, cancellation) go through here so a
 * single code path records the matching event row. Direct connection queries
 * — not the full Magento ORM — for cost reasons; the JobRepository is on the
 * hot path of every dispatch and every worker tick.
 */
class Hirale_Queue_Model_JobRepository
{
    /**
     * Insert a new job row. Returns the generated stable job_id.
     *
     * @param array<string, mixed> $payload  Serialized envelope payload (encoded later as JSON).
     * @param array<string, mixed> $metadata Operator-supplied metadata (encoded as JSON).
     */
    public function create(
        string $messageClass,
        string $queueName,
        array $payload,
        array $metadata,
        int $maxAttempts,
        int $retryBackoffBase,
        int $retryBackoffCap,
        ?int $storeId,
        int $delaySeconds = 0,
    ): string {
        $jobId = $this->generateJobId();
        $now   = Mage_Core_Model_Locale::nowUtc();
        $available = $delaySeconds > 0
            ? gmdate('Y-m-d H:i:s', time() + $delaySeconds)
            : $now;

        $conn = $this->writeAdapter();
        $conn->insert($this->jobTable(), [
            'job_id'             => $jobId,
            'queue_name'         => $queueName,
            'message_class'      => $messageClass,
            'status'             => Hirale_Queue_Model_Job::STATUS_QUEUED,
            'payload_json'       => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'metadata_json'      => $metadata === [] ? null : json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'store_id'           => $storeId,
            'attempt'            => 0,
            'max_attempts'       => $maxAttempts,
            'retry_backoff_base' => $retryBackoffBase,
            'retry_backoff_cap'  => $retryBackoffCap,
            'available_at'       => $available,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        $this->recordEvent($jobId, null, Hirale_Queue_Model_Job::STATUS_QUEUED, 0, null);
        return $jobId;
    }

    public function findByJobId(string $jobId): ?array
    {
        $conn = $this->readAdapter();
        $row  = $conn->fetchRow(
            $conn->select()->from($this->jobTable())->where('job_id = ?', $jobId),
        );
        return $row === false ? null : $row;
    }

    public function findByTransportId(string $transportId): ?array
    {
        $conn = $this->readAdapter();
        $row  = $conn->fetchRow(
            $conn->select()->from($this->jobTable())->where('transport_id = ?', $transportId),
        );
        return $row === false ? null : $row;
    }

    /**
     * Full state-transition history for one job, oldest first. Backs the
     * admin job detail page.
     *
     * @return list<array<string, mixed>>
     */
    public function eventsForJob(string $jobId): array
    {
        $conn = $this->readAdapter();
        return $conn->fetchAll(
            $conn->select()
                ->from($this->eventTable())
                ->where('job_id = ?', $jobId)
                ->order('occurred_at ASC')
                ->order('entity_id ASC'),
        );
    }

    public function setTransportId(string $jobId, string $transportId): void
    {
        $this->writeAdapter()->update(
            $this->jobTable(),
            ['transport_id' => $transportId, 'dispatched_at' => Mage_Core_Model_Locale::nowUtc()],
            ['job_id = ?' => $jobId],
        );
    }

    /**
     * Atomically transition status, optionally update other fields, and record
     * the matching event row.
     *
     * @param array<string, mixed> $extra
     */
    public function transition(string $jobId, string $toStatus, array $extra = [], ?string $errorExcerpt = null): void
    {
        $current = $this->findByJobId($jobId);
        if ($current === null) {
            return;
        }
        $fromStatus = (string) $current['status'];
        $attempt    = (int) ($extra['attempt'] ?? $current['attempt']);

        $update = array_merge($extra, [
            'status'     => $toStatus,
            'updated_at' => Mage_Core_Model_Locale::nowUtc(),
        ]);
        if (Hirale_Queue_Model_Job::isTerminal($toStatus) && !isset($extra['finished_at'])) {
            $update['finished_at'] = Mage_Core_Model_Locale::nowUtc();
        }

        $this->writeAdapter()->update($this->jobTable(), $update, ['job_id = ?' => $jobId]);
        $this->recordEvent($jobId, $fromStatus, $toStatus, $attempt, $errorExcerpt);
    }

    public function incrementAttempt(string $jobId): int
    {
        $conn = $this->writeAdapter();
        $conn->update(
            $this->jobTable(),
            ['attempt' => new Maho\Db\Expr('attempt + 1'), 'updated_at' => Mage_Core_Model_Locale::nowUtc()],
            ['job_id = ?' => $jobId],
        );
        return (int) $conn->fetchOne(
            $conn->select()->from($this->jobTable(), 'attempt')->where('job_id = ?', $jobId),
        );
    }

    public function setError(string $jobId, string $error): void
    {
        $this->writeAdapter()->update(
            $this->jobTable(),
            ['last_error' => mb_substr($error, 0, 65000), 'updated_at' => Mage_Core_Model_Locale::nowUtc()],
            ['job_id = ?' => $jobId],
        );
    }

    /**
     * Failed jobs eligible for bulk retry, oldest failure first.
     *
     * @param string|null $sinceUtc 'Y-m-d H:i:s' UTC lower bound on finished_at
     * @return list<array<string, mixed>>
     */
    public function findFailed(?string $queueName = null, ?string $sinceUtc = null, int $limit = 100): array
    {
        $conn   = $this->readAdapter();
        $select = $conn->select()
            ->from($this->jobTable())
            ->where('status = ?', Hirale_Queue_Model_Job::STATUS_FAILED)
            ->order('finished_at ASC')
            ->limit(max(1, $limit));
        if ($queueName !== null && $queueName !== '') {
            $select->where('queue_name = ?', $queueName);
        }
        if ($sinceUtc !== null && $sinceUtc !== '') {
            $select->where('finished_at >= ?', $sinceUtc);
        }
        return $conn->fetchAll($select);
    }

    /**
     * Close out a failed job that was re-dispatched as a new one. Keeps bulk
     * retry idempotent: a superseded row leaves the failed set, so a second
     * `hirale:queue:retry-failed` run cannot re-dispatch it.
     */
    public function markSuperseded(string $jobId, string $newJobId): bool
    {
        $affected = (int) $this->writeAdapter()->update(
            $this->jobTable(),
            [
                'status'     => Hirale_Queue_Model_Job::STATUS_CANCELED,
                'updated_at' => Mage_Core_Model_Locale::nowUtc(),
            ],
            ['job_id = ?' => $jobId, 'status = ?' => Hirale_Queue_Model_Job::STATUS_FAILED],
        );
        if ($affected > 0) {
            $current = $this->findByJobId($jobId);
            $this->recordEvent(
                $jobId,
                Hirale_Queue_Model_Job::STATUS_FAILED,
                Hirale_Queue_Model_Job::STATUS_CANCELED,
                (int) ($current['attempt'] ?? 0),
                'Superseded by retry; new job ' . $newJobId,
            );
        }
        return $affected > 0;
    }

    public function requestCancel(string $jobId): int
    {
        // Cooperative cancel: flag is honored by the handler at a safe boundary.
        return (int) $this->writeAdapter()->update(
            $this->jobTable(),
            ['cancel_requested' => 1, 'updated_at' => Mage_Core_Model_Locale::nowUtc()],
            ['job_id = ?' => $jobId, 'status = ?' => Hirale_Queue_Model_Job::STATUS_RUNNING],
        );
    }

    public function markCanceledIfPending(string $jobId): bool
    {
        // Immediate cancel for non-running jobs.
        $affected = (int) $this->writeAdapter()->update(
            $this->jobTable(),
            [
                'status'      => Hirale_Queue_Model_Job::STATUS_CANCELED,
                'updated_at'  => Mage_Core_Model_Locale::nowUtc(),
                'finished_at' => Mage_Core_Model_Locale::nowUtc(),
            ],
            [
                'job_id = ?'   => $jobId,
                'status IN(?)' => [
                    Hirale_Queue_Model_Job::STATUS_QUEUED,
                    Hirale_Queue_Model_Job::STATUS_RETRY_WAIT,
                ],
            ],
        );
        if ($affected > 0) {
            $current = $this->findByJobId($jobId);
            $this->recordEvent(
                $jobId,
                null,
                Hirale_Queue_Model_Job::STATUS_CANCELED,
                (int) ($current['attempt'] ?? 0),
                'Canceled by operator',
            );
        }
        return $affected > 0;
    }

    /**
     * Move finished jobs older than $thresholdSeconds into the archive table.
     * Returns the number of rows moved. Idempotent within a batch.
     */
    public function archiveFinished(int $thresholdSeconds, int $batchSize): int
    {
        $cutoff = gmdate('Y-m-d H:i:s', time() - $thresholdSeconds);
        $conn   = $this->writeAdapter();

        $select = $conn->select()
            ->from($this->jobTable())
            ->where('finished_at IS NOT NULL')
            ->where('finished_at < ?', $cutoff)
            ->limit($batchSize);
        $rows = $conn->fetchAll($select);
        if (count($rows) === 0) {
            return 0;
        }

        $now = Mage_Core_Model_Locale::nowUtc();
        $moved = 0;
        $conn->beginTransaction();
        try {
            foreach ($rows as $row) {
                $conn->insert($this->archiveTable(), [
                    'job_id'        => $row['job_id'],
                    'queue_name'    => $row['queue_name'],
                    'message_class' => $row['message_class'],
                    'status'        => $row['status'],
                    'metadata_json' => $row['metadata_json'],
                    'store_id'      => $row['store_id'],
                    'attempt'       => $row['attempt'],
                    'max_attempts'  => $row['max_attempts'],
                    'last_error'    => $row['last_error'],
                    'created_at'    => $row['created_at'],
                    'finished_at'   => $row['finished_at'],
                    'archived_at'   => $now,
                ]);
                $conn->delete($this->jobTable(), ['entity_id = ?' => $row['entity_id']]);
                $moved++;
            }
            // Event rows are not archived; drop them with their job so the
            // event table cannot grow unbounded with orphans.
            $conn->delete($this->eventTable(), ['job_id IN(?)' => array_column($rows, 'job_id')]);
            $conn->commit();
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
        return $moved;
    }

    /**
     * Delete archive rows older than the retention thresholds (per status).
     * Returns the number of rows deleted.
     */
    public function purgeArchive(int $successDays, int $failureDays): int
    {
        $conn      = $this->writeAdapter();
        $successAt = gmdate('Y-m-d H:i:s', time() - ($successDays * 86400));
        $failureAt = gmdate('Y-m-d H:i:s', time() - ($failureDays * 86400));

        $deleted  = (int) $conn->delete($this->archiveTable(), [
            'status = ?'      => Hirale_Queue_Model_Job::STATUS_SUCCEEDED,
            'finished_at < ?' => $successAt,
        ]);
        $deleted += (int) $conn->delete($this->archiveTable(), [
            'status IN(?)'    => [Hirale_Queue_Model_Job::STATUS_FAILED, Hirale_Queue_Model_Job::STATUS_CANCELED],
            'finished_at < ?' => $failureAt,
        ]);
        return $deleted;
    }

    public function recordEvent(
        string $jobId,
        ?string $fromStatus,
        string $toStatus,
        int $attempt,
        ?string $errorExcerpt,
    ): void {
        $this->writeAdapter()->insert($this->eventTable(), [
            'job_id'        => $jobId,
            'from_status'   => $fromStatus,
            'to_status'     => $toStatus,
            'attempt'       => $attempt,
            'error_excerpt' => $errorExcerpt === null ? null : mb_substr($errorExcerpt, 0, 1024),
            'occurred_at'   => Mage_Core_Model_Locale::nowUtc(),
        ]);
    }

    protected function generateJobId(): string
    {
        // 32-hex job id; transport-id stays separate.
        return bin2hex(random_bytes(16));
    }

    protected function readAdapter(): \Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('hirale_queue_read');
    }

    protected function writeAdapter(): \Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('hirale_queue_write');
    }

    protected function jobTable(): string
    {
        return (string) Mage::getSingleton('core/resource')->getTableName('hirale_queue/job');
    }

    protected function eventTable(): string
    {
        return (string) Mage::getSingleton('core/resource')->getTableName('hirale_queue/job_event');
    }

    protected function archiveTable(): string
    {
        return (string) Mage::getSingleton('core/resource')->getTableName('hirale_queue/job_archive');
    }
}
