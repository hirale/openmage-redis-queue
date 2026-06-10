<?php

/**
 * Upgrade path for OpenMage installs on the v1.1.x module line. Same
 * semantics as upgrade-1.0.0-3.0.0.php: v3 tables are created (guarded),
 * v1 tables are left untouched, configuration is re-entered once in admin.
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
include __DIR__ . '/install-3.0.0.php';
