<?php

declare(strict_types=1);

/** @var Mage_Core_Model_Resource_Setup $this */
$installer = $this;
$installer->startSetup();

$migrator = Mage::getModel('hirale_queue/configMigrator');
if ($migrator instanceof Hirale_Queue_Model_ConfigMigrator) {
    $migrator->migrate($installer->getConnection(), $installer->getTable('core/config_data'));
}

$installer->endSetup();
