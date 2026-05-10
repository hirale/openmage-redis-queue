<?php

declare(strict_types=1);

class Hirale_Queue_Model_ConfigMigrator
{
    /** @var list<string> */
    private const FIELDS = [
        'enabled',
        'dsn',
        'scheme',
        'host',
        'port',
        'database',
        'count',
        'stream_key',
        'group',
        'consumer',
    ];

    /**
     * Copy saved config values from the legacy System section to Hirale > Queue.
     *
     * Existing new-path values always win, so rerunning the migration is safe.
     *
     * @param object $connection Magento/OpenMage/Maho DB adapter.
     */
    public function migrate($connection, string $table): void
    {
        foreach (self::FIELDS as $field) {
            $this->_migrateField($connection, $table, $field);
        }
    }

    /**
     * @param object $connection Magento/OpenMage/Maho DB adapter.
     */
    private function _migrateField($connection, string $table, string $field): void
    {
        $legacyPath = Hirale_Queue_Helper_Data::XML_PATH_LEGACY_PREFIX . $field;
        $newPath = Hirale_Queue_Helper_Data::XML_PATH_PREFIX . $field;

        $rows = $connection->fetchAll(sprintf(
            'SELECT scope, scope_id, value FROM %s WHERE path = %s',
            $table,
            $connection->quote($legacyPath),
        ));

        foreach ($rows as $row) {
            $scope = (string) ($row['scope'] ?? 'default');
            $scopeId = (int) ($row['scope_id'] ?? 0);
            if ($this->_newPathExists($connection, $table, $scope, $scopeId, $newPath)) {
                continue;
            }

            $connection->insert($table, [
                'scope' => $scope,
                'scope_id' => $scopeId,
                'path' => $newPath,
                'value' => (string) ($row['value'] ?? ''),
            ]);
        }
    }

    /**
     * @param object $connection Magento/OpenMage/Maho DB adapter.
     */
    private function _newPathExists($connection, string $table, string $scope, int $scopeId, string $newPath): bool
    {
        $existingId = $connection->fetchOne(sprintf(
            'SELECT config_id FROM %s WHERE scope = %s AND scope_id = %d AND path = %s LIMIT 1',
            $table,
            $connection->quote($scope),
            $scopeId,
            $connection->quote($newPath),
        ));

        return $existingId !== false && $existingId !== null;
    }
}
