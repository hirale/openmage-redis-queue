<?php

/**
 * Aggregations for the admin dashboard and the `hirale:queue:stats` CLI.
 * Reads directly from hirale_queue_job; cheap when the indexes from
 * install-3.0.0.php are present.
 */
class Hirale_Queue_Model_Stats
{
    /**
     * Per-status totals across all queues.
     *
     * @return array<string, int> status => count
     */
    public function totalsByStatus(): array
    {
        $conn = $this->readAdapter();
        $rows = $conn->fetchAll(
            $conn->select()
                ->from($this->jobTable(), ['status', 'cnt' => new Maho\Db\Expr('COUNT(*)')])
                ->group('status'),
        );
        $totals = array_fill_keys(array_keys(Hirale_Queue_Model_Job::statuses()), 0);
        foreach ($rows as $row) {
            $totals[(string) $row['status']] = (int) $row['cnt'];
        }
        return $totals;
    }

    /**
     * Per-queue depth (number of queued + retry_wait + running jobs) and the
     * age in seconds of the oldest active job.
     *
     * @return list<array{queue: string, depth: int, oldest_seconds: int}>
     */
    public function perQueueDepth(): array
    {
        $conn = $this->readAdapter();
        $rows = $conn->fetchAll(
            $conn->select()
                ->from(
                    $this->jobTable(),
                    [
                        'queue'    => 'queue_name',
                        'depth'    => new Maho\Db\Expr('COUNT(*)'),
                        'oldest'   => new Maho\Db\Expr('MIN(created_at)'),
                    ],
                )
                ->where('status IN(?)', [
                    Hirale_Queue_Model_Job::STATUS_QUEUED,
                    Hirale_Queue_Model_Job::STATUS_RETRY_WAIT,
                    Hirale_Queue_Model_Job::STATUS_RUNNING,
                ])
                ->group('queue_name')
                ->order('queue_name ASC'),
        );
        $now = time();
        return array_map(
            static function (array $row) use ($now): array {
                $oldestEpoch = $row['oldest'] ? (int) strtotime((string) $row['oldest']) : $now;
                return [
                    'queue'          => (string) $row['queue'],
                    'depth'          => (int) $row['depth'],
                    'oldest_seconds' => max(0, $now - $oldestEpoch),
                ];
            },
            $rows,
        );
    }

    /**
     * Age in seconds of the oldest non-finished job across all queues, or null
     * if no such job exists. Used by the health CLI.
     */
    public function oldestQueuedAgeSeconds(): ?int
    {
        $conn = $this->readAdapter();
        $oldest = $conn->fetchOne(
            $conn->select()
                ->from($this->jobTable(), ['oldest' => new Maho\Db\Expr('MIN(created_at)')])
                ->where('status IN(?)', [
                    Hirale_Queue_Model_Job::STATUS_QUEUED,
                    Hirale_Queue_Model_Job::STATUS_RETRY_WAIT,
                    Hirale_Queue_Model_Job::STATUS_RUNNING,
                ]),
        );
        if ($oldest === false || $oldest === null) {
            return null;
        }
        return max(0, time() - (int) strtotime((string) $oldest));
    }

    protected function readAdapter(): \Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('hirale_queue_read');
    }

    protected function jobTable(): string
    {
        return (string) Mage::getSingleton('core/resource')->getTableName('hirale_queue/job');
    }
}
