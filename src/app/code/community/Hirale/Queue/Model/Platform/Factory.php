<?php

declare(strict_types=1);

class Hirale_Queue_Model_Platform_Factory
{
    /**
     * Resolve the adapter for the current runtime.
     *
     * Callers should depend on Hirale_Queue_Model_Platform_AdapterInterface
     * instead of branching directly on Maho/OpenMage class names.
     */
    public function getAdapter(): Hirale_Queue_Model_Platform_AdapterInterface
    {
        $helper = Mage::helper('hirale_queue/platform');
        if (!$helper instanceof Hirale_Queue_Helper_Platform) {
            throw new RuntimeException('Hirale Queue platform helper is unavailable.');
        }

        $alias = $helper->isMaho()
            ? 'hirale_queue/platform_maho'
            : 'hirale_queue/platform_openmage';

        $adapter = Mage::getSingleton($alias);
        if (!$adapter instanceof Hirale_Queue_Model_Platform_AdapterInterface) {
            throw new RuntimeException(sprintf('Hirale Queue platform adapter "%s" is unavailable.', $alias));
        }

        return $adapter;
    }
}
