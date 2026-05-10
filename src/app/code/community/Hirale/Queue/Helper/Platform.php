<?php

declare(strict_types=1);

class Hirale_Queue_Helper_Platform extends Mage_Core_Helper_Abstract
{
    public const CODE_MAHO = 'maho';
    public const CODE_OPENMAGE = 'openmage';

    /**
     * Return the runtime platform code used by platform-specific adapters.
     */
    public function getCode(): string
    {
        return $this->isMaho() ? self::CODE_MAHO : self::CODE_OPENMAGE;
    }

    /**
     * Detect Maho by classes that exist only in Maho's namespaced runtime.
     *
     * OpenMage keeps the Magento 1 class layout, so absence of these classes is
     * treated as OpenMage by default.
     */
    public function isMaho(): bool
    {
        return class_exists('Maho\\DataObject') || class_exists('Maho\\Event\\Observer');
    }

    /**
     * Return true when the runtime should use OpenMage-compatible behavior.
     */
    public function isOpenMage(): bool
    {
        return !$this->isMaho();
    }
}
