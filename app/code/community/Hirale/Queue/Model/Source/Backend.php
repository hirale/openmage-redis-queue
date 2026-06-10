<?php

/**
 * Options for the system.xml backend picker dropdown.
 *
 * Mirrors Maho's mail transport dropdown
 * (Mage_Adminhtml_Model_System_Config_Source_Email_Transport): options whose
 * composer bridge package is missing get a "⚠️ Install symfony/…" suffix so
 * the operator sees the requirement before saving.
 */
class Hirale_Queue_Model_Source_Backend
{
    /**
     * @return list<array{value: string, label: string}>
     */
    public function toOptionArray(): array
    {
        $helper = Mage::helper('core');
        $options = [
            ['value' => Hirale_Queue_Helper_Data::BACKEND_REDIS,    'label' => 'Redis'],
            ['value' => Hirale_Queue_Helper_Data::BACKEND_DOCTRINE, 'label' => 'Doctrine (database)'],
            ['value' => Hirale_Queue_Helper_Data::BACKEND_AMQP,     'label' => 'AMQP / RabbitMQ'],
            ['value' => Hirale_Queue_Helper_Data::BACKEND_SQS,      'label' => 'Amazon SQS'],
        ];

        foreach ($options as $k => $option) {
            $package = Hirale_Queue_Model_ConnectionTester::REQUIRED_PACKAGES[$option['value']] ?? null;
            // packageInstallWarning is Maho-only; OpenMage admins simply get
            // no inline hint (save-time validation still reports the gap).
            if ($package !== null && method_exists($helper, 'packageInstallWarning')) {
                $options[$k]['label'] .= $helper->packageInstallWarning($package);
            }
        }

        return $options;
    }
}
