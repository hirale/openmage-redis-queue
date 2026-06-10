<?php

use Hirale\Queue\MessageBusFactory;
use Hirale\Queue\TransportDsnBuilder;

/**
 * Magento event observers for the queue module:
 *
 * - validateConfigOnSave: hooked on
 *   `controller_action_predispatch_adminhtml_system_config_save`. Reads the
 *   incoming form values, assembles the backend DSN, tries a real connection,
 *   and throws a Mage_Core_Exception on failure. The exception interrupts the
 *   admin save controller before any rows hit core_config_data, so a bad
 *   config is never persisted — operators see the prior values intact and a
 *   red banner explaining what failed.
 *
 * - clearBusCache: hooked on
 *   `admin_system_config_changed_section_hirale_queue`. Saved successfully —
 *   reset the cached bus so the next dispatch picks up the new config.
 *
 * Form parsing and the actual probe live in Hirale_Queue_Model_ConnectionTester,
 * shared with the Test Connection button in System Configuration.
 */
class Hirale_Queue_Model_Observer
{
    public function validateConfigOnSave(\Maho\Event\Observer $observer): void
    {
        $request = Mage::app()->getRequest();
        $section = (string) $request->getParam('section');
        if ($section !== 'hirale_queue') {
            return;
        }

        $groups = (array) $request->getParam('groups', []);
        if ($groups === []) {
            return;
        }

        $tester     = $this->connectionTester();
        $backendCfg = $tester->buildBackendConfigFromForm($groups);
        $queues     = $tester->parseQueueListFromForm($groups);

        if ($queues === []) {
            throw new Mage_Core_Exception(
                Mage::helper('hirale_queue')->__('At least one queue must be defined.'),
            );
        }
        if (count($queues) !== count(array_unique($queues))) {
            throw new Mage_Core_Exception(
                Mage::helper('hirale_queue')->__('Queue names must be unique.'),
            );
        }

        try {
            (new TransportDsnBuilder())->build($backendCfg, $queues[0]);
        } catch (\Throwable $e) {
            throw new Mage_Core_Exception(
                Mage::helper('hirale_queue')->__('Backend config invalid: %s', $e->getMessage()),
            );
        }

        try {
            $tester->probe($backendCfg);
        } catch (\Throwable $e) {
            throw new Mage_Core_Exception(
                Mage::helper('hirale_queue')->__('Could not reach the configured backend: %s', $e->getMessage()),
            );
        }
    }

    public function clearBusCache(\Maho\Event\Observer $observer): void
    {
        MessageBusFactory::reset();
    }

    private function connectionTester(): Hirale_Queue_Model_ConnectionTester
    {
        return Mage::getSingleton('hirale_queue/connectionTester');
    }
}
