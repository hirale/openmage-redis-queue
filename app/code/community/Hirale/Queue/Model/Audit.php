<?php

/**
 * Admin-action audit trail. Inserts into hirale_queue_audit. Failure is logged
 * but does not propagate — auditing should never block the operator action.
 */
class Hirale_Queue_Model_Audit
{
    public const ACTION_RETRY            = 'retry';
    public const ACTION_CANCEL           = 'cancel';
    public const ACTION_PURGE            = 'purge';
    public const ACTION_TEST_CONNECTION  = 'test_connection';

    public function record(string $action, ?string $jobId = null): void
    {
        if ($action === '') {
            return;
        }
        if (!Mage::helper('hirale_queue')->isAuditAdminActionsEnabled()) {
            return;
        }
        try {
            $session = Mage::getSingleton('admin/session');
            $user    = $session->getUser();
            $request = Mage::app()->getRequest();
            $this->writeAdapter()->insert($this->auditTable(), [
                'admin_user_id'  => $user ? (int) $user->getId() : null,
                'admin_username' => $user ? (string) $user->getUsername() : null,
                'action'         => $action,
                'job_id'         => $jobId,
                'ip'             => $request ? (string) $request->getClientIp() : null,
                'created_at'     => Mage_Core_Model_Locale::nowUtc(),
            ]);
        } catch (Exception $e) {
            Mage::logException($e);
        }
    }

    /**
     * Delete audit rows older than $days. Returns the number of rows removed.
     * Called by the nightly cron so the trail cannot grow unbounded.
     */
    public function purgeOlderThan(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }
        $cutoff = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
        return (int) $this->writeAdapter()->delete($this->auditTable(), ['created_at < ?' => $cutoff]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 50): array
    {
        $conn = $this->readAdapter();
        return $conn->fetchAll(
            $conn->select()
                ->from($this->auditTable())
                ->order('created_at DESC')
                ->limit($limit),
        );
    }

    protected function readAdapter(): \Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('hirale_queue_read');
    }

    protected function writeAdapter(): \Maho\Db\Adapter\AdapterInterface
    {
        return Mage::getSingleton('core/resource')->getConnection('hirale_queue_write');
    }

    protected function auditTable(): string
    {
        return (string) Mage::getSingleton('core/resource')->getTableName('hirale_queue/audit');
    }
}
