<?php

/**
 * Hirale_Queue 3.0.0 — fresh install.
 *
 * Four tables: job (hot), job_event (state-transition audit), job_archive (cold),
 * audit (admin action trail). v3 starts from a clean schema with no upgrade chain
 * from v2 — the rename + package-rewrite is the line in the sand.
 *
 * @var Mage_Core_Model_Resource_Setup $installer
 */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();

// ====================================================================
// hirale_queue_job — active jobs (hot table)
// ====================================================================
$tableJob = $installer->getTable('hirale_queue/job');
if (!$connection->isTableExists($tableJob)) {
    $table = $connection->newTable($tableJob)
        ->addColumn('entity_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'identity' => true,
            'primary'  => true,
            'nullable' => false,
        ], 'Entity ID')
        ->addColumn('job_id', Varien_Db_Ddl_Table::TYPE_VARCHAR, 64, [
            'nullable' => false,
        ], 'Stable job ID across the message lifecycle')
        ->addColumn('transport_id', Varien_Db_Ddl_Table::TYPE_VARCHAR, 128, [
            'nullable' => true,
        ], 'Backend-specific message id (Redis stream id, AMQP delivery tag, SQS message id, Doctrine row id)')
        ->addColumn('queue_name', Varien_Db_Ddl_Table::TYPE_VARCHAR, 64, [
            'nullable' => false,
            'default'  => 'default',
        ], 'Logical queue name')
        ->addColumn('message_class', Varien_Db_Ddl_Table::TYPE_VARCHAR, 255, [
            'nullable' => false,
        ], 'Symfony Messenger message class FQCN')
        ->addColumn('status', Varien_Db_Ddl_Table::TYPE_VARCHAR, 32, [
            'nullable' => false,
            'default'  => 'queued',
        ], 'Job lifecycle state')
        ->addColumn('payload_json', Varien_Db_Ddl_Table::TYPE_TEXT, '4M', [
            'nullable' => true,
        ], 'Serialized Messenger envelope payload')
        ->addColumn('metadata_json', Varien_Db_Ddl_Table::TYPE_TEXT, 524288, [
            'nullable' => true,
        ], 'Operator-supplied metadata')
        ->addColumn('store_id', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, [
            'unsigned' => true,
            'nullable' => true,
        ], 'Dispatching store ID')
        ->addColumn('attempt', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'nullable' => false,
            'default'  => 0,
        ], 'Current attempt counter')
        ->addColumn('max_attempts', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'nullable' => false,
            'default'  => 3,
        ], 'Retry limit captured at dispatch')
        ->addColumn('retry_backoff_base', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'nullable' => false,
            'default'  => 5,
        ], 'Backoff base seconds captured at dispatch')
        ->addColumn('retry_backoff_cap', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'nullable' => false,
            'default'  => 3600,
        ], 'Backoff cap seconds captured at dispatch')
        ->addColumn('last_error', Varien_Db_Ddl_Table::TYPE_TEXT, 65536, [
            'nullable' => true,
        ], 'Most recent failure reason excerpt')
        ->addColumn('cancel_requested', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, [
            'nullable' => false,
            'default'  => 0,
        ], 'Cooperative cancel flag — handler checks at safe boundary')
        ->addColumn('available_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [
            'nullable' => false,
            'default'  => Varien_Db_Ddl_Table::TIMESTAMP_INIT,
        ], 'Next execution time (for delayed / retry_wait jobs)')
        ->addColumn('dispatched_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [
            'nullable' => true,
        ], 'When the envelope was sent to the transport')
        ->addColumn('started_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [
            'nullable' => true,
        ], 'When the worker began processing')
        ->addColumn('finished_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [
            'nullable' => true,
        ], 'When the job reached a terminal state')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [
            'nullable' => false,
            'default'  => Varien_Db_Ddl_Table::TIMESTAMP_INIT,
        ], 'Row creation time')
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [
            'nullable' => false,
            'default'  => Varien_Db_Ddl_Table::TIMESTAMP_INIT_UPDATE,
        ], 'Last update time')
        ->addIndex(
            $installer->getIdxName('hirale_queue/job', ['job_id'], Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            ['job_id'],
            ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE],
        )
        ->addIndex(
            $installer->getIdxName('hirale_queue/job', ['status', 'available_at']),
            ['status', 'available_at'],
        )
        ->addIndex(
            $installer->getIdxName('hirale_queue/job', ['queue_name', 'status', 'available_at']),
            ['queue_name', 'status', 'available_at'],
        )
        ->addIndex(
            $installer->getIdxName('hirale_queue/job', ['transport_id']),
            ['transport_id'],
        )
        ->setComment('Hirale Queue: active jobs (hot)');
    $connection->createTable($table);
}

// ====================================================================
// hirale_queue_job_event — state-transition audit
// ====================================================================
$tableJobEvent = $installer->getTable('hirale_queue/job_event');
if (!$connection->isTableExists($tableJobEvent)) {
    $table = $connection->newTable($tableJobEvent)
        ->addColumn('entity_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'identity' => true,
            'primary'  => true,
            'nullable' => false,
        ], 'Entity ID')
        ->addColumn('job_id', Varien_Db_Ddl_Table::TYPE_VARCHAR, 64, [
            'nullable' => false,
        ], 'Reference to hirale_queue_job.job_id')
        ->addColumn('from_status', Varien_Db_Ddl_Table::TYPE_VARCHAR, 32, [
            'nullable' => true,
        ], 'Prior status (NULL for the initial transition)')
        ->addColumn('to_status', Varien_Db_Ddl_Table::TYPE_VARCHAR, 32, [
            'nullable' => false,
        ], 'New status')
        ->addColumn('attempt', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'nullable' => false,
            'default'  => 0,
        ], 'Attempt counter at the time of the transition')
        ->addColumn('error_excerpt', Varien_Db_Ddl_Table::TYPE_TEXT, 1024, [
            'nullable' => true,
        ], 'Failure reason excerpt (1024 char limit)')
        ->addColumn('occurred_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [
            'nullable' => false,
            'default'  => Varien_Db_Ddl_Table::TIMESTAMP_INIT,
        ], 'Transition timestamp')
        ->addIndex(
            $installer->getIdxName('hirale_queue/job_event', ['job_id', 'occurred_at']),
            ['job_id', 'occurred_at'],
        )
        ->addIndex(
            $installer->getIdxName('hirale_queue/job_event', ['to_status', 'occurred_at']),
            ['to_status', 'occurred_at'],
        )
        ->setComment('Hirale Queue: per-job state-transition audit');
    $connection->createTable($table);
}

// ====================================================================
// hirale_queue_job_archive — finished jobs (cold)
// ====================================================================
$tableJobArchive = $installer->getTable('hirale_queue/job_archive');
if (!$connection->isTableExists($tableJobArchive)) {
    $table = $connection->newTable($tableJobArchive)
        ->addColumn('entity_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'identity' => true,
            'primary'  => true,
            'nullable' => false,
        ], 'Entity ID')
        ->addColumn('job_id', Varien_Db_Ddl_Table::TYPE_VARCHAR, 64, [
            'nullable' => false,
        ], 'Stable job ID')
        ->addColumn('queue_name', Varien_Db_Ddl_Table::TYPE_VARCHAR, 64, [
            'nullable' => false,
            'default'  => 'default',
        ], 'Logical queue name')
        ->addColumn('message_class', Varien_Db_Ddl_Table::TYPE_VARCHAR, 255, [
            'nullable' => false,
        ], 'Symfony Messenger message class FQCN')
        ->addColumn('status', Varien_Db_Ddl_Table::TYPE_VARCHAR, 32, [
            'nullable' => false,
        ], 'Terminal status (succeeded / failed / canceled)')
        ->addColumn('metadata_json', Varien_Db_Ddl_Table::TYPE_TEXT, 524288, [
            'nullable' => true,
        ], 'Preserved metadata')
        ->addColumn('store_id', Varien_Db_Ddl_Table::TYPE_SMALLINT, null, [
            'unsigned' => true,
            'nullable' => true,
        ], 'Dispatching store ID')
        ->addColumn('attempt', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'nullable' => false,
            'default'  => 0,
        ], 'Final attempt count')
        ->addColumn('max_attempts', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'nullable' => false,
            'default'  => 3,
        ], 'Max attempts policy')
        ->addColumn('last_error', Varien_Db_Ddl_Table::TYPE_TEXT, 65536, [
            'nullable' => true,
        ], 'Terminal failure reason')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [
            'nullable' => false,
        ], 'Original job creation time')
        ->addColumn('finished_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [
            'nullable' => false,
        ], 'Job completion time')
        ->addColumn('archived_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [
            'nullable' => false,
            'default'  => Varien_Db_Ddl_Table::TIMESTAMP_INIT,
        ], 'Archive move time')
        ->addIndex(
            $installer->getIdxName('hirale_queue/job_archive', ['job_id'], Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE),
            ['job_id'],
            ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE],
        )
        ->addIndex(
            $installer->getIdxName('hirale_queue/job_archive', ['status', 'finished_at']),
            ['status', 'finished_at'],
        )
        ->setComment('Hirale Queue: finished jobs (cold)');
    $connection->createTable($table);
}

// ====================================================================
// hirale_queue_audit — admin action trail
// ====================================================================
$tableAudit = $installer->getTable('hirale_queue/audit');
if (!$connection->isTableExists($tableAudit)) {
    $table = $connection->newTable($tableAudit)
        ->addColumn('entity_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'identity' => true,
            'primary'  => true,
            'nullable' => false,
        ], 'Entity ID')
        ->addColumn('admin_user_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'unsigned' => true,
            'nullable' => true,
        ], 'Admin user ID')
        ->addColumn('admin_username', Varien_Db_Ddl_Table::TYPE_VARCHAR, 64, [
            'nullable' => true,
        ], 'Admin username (preserved if admin user is later deleted)')
        ->addColumn('action', Varien_Db_Ddl_Table::TYPE_VARCHAR, 32, [
            'nullable' => false,
        ], 'retry / cancel / purge / test_connection')
        ->addColumn('job_id', Varien_Db_Ddl_Table::TYPE_VARCHAR, 64, [
            'nullable' => true,
        ], 'Targeted job (NULL for bulk ops)')
        ->addColumn('ip', Varien_Db_Ddl_Table::TYPE_VARCHAR, 45, [
            'nullable' => true,
        ], 'Client IP address')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_DATETIME, null, [
            'nullable' => false,
            'default'  => Varien_Db_Ddl_Table::TIMESTAMP_INIT,
        ], 'Action timestamp')
        ->addIndex(
            $installer->getIdxName('hirale_queue/audit', ['action', 'created_at']),
            ['action', 'created_at'],
        )
        ->addIndex(
            $installer->getIdxName('hirale_queue/audit', ['job_id']),
            ['job_id'],
        )
        ->setComment('Hirale Queue: admin action audit trail');
    $connection->createTable($table);
}

$installer->endSetup();
