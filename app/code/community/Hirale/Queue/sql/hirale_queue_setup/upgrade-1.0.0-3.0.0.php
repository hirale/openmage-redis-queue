<?php

/**
 * Upgrade path for OpenMage installs coming from the published v1.0.0
 * module. The v3 schema is unrelated to v1's tables (which are left
 * untouched); the install script is fully guarded with isTableExists, so it
 * doubles as the upgrade. v1 configuration is NOT migrated — reconfigure
 * the backend once under System > Configuration > Hirale > Queue (save-time
 * validation verifies the connection).
 *
 * @var Mage_Core_Model_Resource_Setup $this
 */
include __DIR__ . '/install-3.0.0.php';
