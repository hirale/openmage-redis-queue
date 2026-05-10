<?php

declare(strict_types=1);

/** @var Mage_Core_Model_Resource_Setup $this */
$installer = $this;
$installer->startSetup();

$connection = $installer->getConnection();
$tableName = $installer->getTable('hirale_queue/job');
if (!$connection->isTableExists($tableName)) {
    $table = $connection
        ->newTable($tableName)
        ->addColumn('entity_id', Varien_Db_Ddl_Table::TYPE_INTEGER, null, [
            'identity' => true,
            'unsigned' => true,
            'nullable' => false,
            'primary' => true,
        ], 'Entity ID')
        ->addColumn('job_id', Varien_Db_Ddl_Table::TYPE_TEXT, 64, ['nullable' => false], 'Stable Job ID')
        ->addColumn('queue_name', Varien_Db_Ddl_Table::TYPE_TEXT, 64, ['nullable' => false, 'default' => 'default'], 'Queue Name')
        ->addColumn('handler', Varien_Db_Ddl_Table::TYPE_TEXT, 255, ['nullable' => false], 'Mage Handler Alias')
        ->addColumn('status', Varien_Db_Ddl_Table::TYPE_TEXT, 32, ['nullable' => false, 'default' => 'queued'], 'Job Status')
        ->addColumn('payload_json', Varien_Db_Ddl_Table::TYPE_TEXT, '2M', ['nullable' => true], 'Payload JSON')
        ->addColumn('metadata_json', Varien_Db_Ddl_Table::TYPE_TEXT, '512k', ['nullable' => true], 'Metadata JSON')
        ->addColumn('attempt', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['nullable' => false, 'default' => 0], 'Current Attempt')
        ->addColumn('max_attempts', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['nullable' => false, 'default' => 3], 'Maximum Attempts')
        ->addColumn('retry_delay', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['nullable' => false, 'default' => 60], 'Retry Delay Seconds')
        ->addColumn('timeout', Varien_Db_Ddl_Table::TYPE_INTEGER, null, ['nullable' => false, 'default' => 60], 'Timeout Seconds')
        ->addColumn('stream_id', Varien_Db_Ddl_Table::TYPE_TEXT, 64, ['nullable' => true], 'Redis Stream ID')
        ->addColumn('last_error', Varien_Db_Ddl_Table::TYPE_TEXT, '64k', ['nullable' => true], 'Last Error')
        ->addColumn('available_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['nullable' => false], 'Available At')
        ->addColumn('created_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['nullable' => false], 'Created At')
        ->addColumn('updated_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['nullable' => false], 'Updated At')
        ->addColumn('finished_at', Varien_Db_Ddl_Table::TYPE_TIMESTAMP, null, ['nullable' => true], 'Finished At')
        ->addIndex($installer->getIdxName('hirale_queue/job', ['job_id'], Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE), ['job_id'], ['type' => Varien_Db_Adapter_Interface::INDEX_TYPE_UNIQUE])
        ->addIndex($installer->getIdxName('hirale_queue/job', ['status', 'available_at']), ['status', 'available_at'])
        ->addIndex($installer->getIdxName('hirale_queue/job', ['handler']), ['handler'])
        ->addIndex($installer->getIdxName('hirale_queue/job', ['stream_id']), ['stream_id'])
        ->setComment('Hirale Queue Jobs');

    $connection->createTable($table);
}

$migrator = Mage::getModel('hirale_queue/configMigrator');
if ($migrator instanceof Hirale_Queue_Model_ConfigMigrator) {
    $migrator->migrate($connection, $installer->getTable('core/config_data'));
}

$installer->endSetup();
