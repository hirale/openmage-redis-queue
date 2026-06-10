<?php

/**
 * Config getters for Hirale_Queue.
 *
 * Backend connection fields live at hirale_queue/backend/* and are global only
 * (infrastructure does not vary by store). Operational and retention fields live
 * at hirale_queue/operational/* and hirale_queue/retention/* and are store-scoped
 * — producer code passes the dispatching store id so the envelope captures the
 * right policy.
 */
class Hirale_Queue_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const BACKEND_REDIS    = 'redis';
    public const BACKEND_DOCTRINE = 'doctrine';
    public const BACKEND_AMQP     = 'amqp';
    public const BACKEND_SQS      = 'sqs';

    private const PATH_BACKEND_PREFIX     = 'hirale_queue/backend/';
    private const PATH_QUEUES_LIST        = 'hirale_queue/queues/list';
    private const PATH_OPERATIONAL_PREFIX = 'hirale_queue/operational/';
    private const PATH_RETENTION_PREFIX   = 'hirale_queue/retention/';

    public function getBackend(): string
    {
        $value = (string) Mage::getStoreConfig(self::PATH_BACKEND_PREFIX . 'type');
        return $value !== '' ? $value : self::BACKEND_REDIS;
    }

    /**
     * Build the backend-config array used by lib/Hirale/Queue/TransportDsnBuilder.
     * Always returns global-scope values; secrets are decrypted here.
     *
     * @return array<string, scalar|null>
     */
    public function getBackendConfig(): array
    {
        $backend = $this->getBackend();
        $config = ['type' => $backend];
        switch ($backend) {
            case self::BACKEND_REDIS:
                $config += [
                    'redis_host'            => (string) Mage::getStoreConfig(self::PATH_BACKEND_PREFIX . 'redis_host'),
                    'redis_port'            => (int) Mage::getStoreConfig(self::PATH_BACKEND_PREFIX . 'redis_port') ?: 6379,
                    'redis_password'        => $this->decryptIfNotEmpty((string) Mage::getStoreConfig(self::PATH_BACKEND_PREFIX . 'redis_password')),
                    'redis_database'        => (int) Mage::getStoreConfig(self::PATH_BACKEND_PREFIX . 'redis_database'),
                    'redis_use_tls'         => (bool) Mage::getStoreConfigFlag(self::PATH_BACKEND_PREFIX . 'redis_use_tls'),
                    'redis_tls_verify_peer' => (bool) Mage::getStoreConfigFlag(self::PATH_BACKEND_PREFIX . 'redis_tls_verify_peer'),
                    'redis_tls_cafile'      => (string) Mage::getStoreConfig(self::PATH_BACKEND_PREFIX . 'redis_tls_cafile'),
                ];
                break;
            case self::BACKEND_DOCTRINE:
                $config += [
                    'doctrine_connection' => (string) Mage::getStoreConfig(self::PATH_BACKEND_PREFIX . 'doctrine_connection') ?: 'core_write',
                    'doctrine_table_name' => (string) Mage::getStoreConfig(self::PATH_BACKEND_PREFIX . 'doctrine_table_name') ?: 'hirale_queue_messages',
                    'doctrine_auto_setup' => (bool) Mage::getStoreConfigFlag(self::PATH_BACKEND_PREFIX . 'doctrine_auto_setup'),
                ];
                break;
            case self::BACKEND_AMQP:
                $config += [
                    'amqp_host'     => (string) Mage::getStoreConfig(self::PATH_BACKEND_PREFIX . 'amqp_host'),
                    'amqp_port'     => (int) Mage::getStoreConfig(self::PATH_BACKEND_PREFIX . 'amqp_port') ?: 5672,
                    'amqp_user'     => (string) Mage::getStoreConfig(self::PATH_BACKEND_PREFIX . 'amqp_user'),
                    'amqp_password' => $this->decryptIfNotEmpty((string) Mage::getStoreConfig(self::PATH_BACKEND_PREFIX . 'amqp_password')),
                    'amqp_vhost'    => (string) Mage::getStoreConfig(self::PATH_BACKEND_PREFIX . 'amqp_vhost') ?: '/',
                    'amqp_exchange' => (string) Mage::getStoreConfig(self::PATH_BACKEND_PREFIX . 'amqp_exchange') ?: 'hirale_queue',
                ];
                break;
            case self::BACKEND_SQS:
                $config += [
                    'sqs_region'               => (string) Mage::getStoreConfig(self::PATH_BACKEND_PREFIX . 'sqs_region'),
                    'sqs_queue_url'            => (string) Mage::getStoreConfig(self::PATH_BACKEND_PREFIX . 'sqs_queue_url'),
                    'sqs_use_instance_profile' => (bool) Mage::getStoreConfigFlag(self::PATH_BACKEND_PREFIX . 'sqs_use_instance_profile'),
                    'sqs_access_key'           => $this->decryptIfNotEmpty((string) Mage::getStoreConfig(self::PATH_BACKEND_PREFIX . 'sqs_access_key')),
                    'sqs_secret_key'           => $this->decryptIfNotEmpty((string) Mage::getStoreConfig(self::PATH_BACKEND_PREFIX . 'sqs_secret_key')),
                ];
                break;
        }
        return $config;
    }

    /**
     * @return list<string>
     */
    public function getQueueList(): array
    {
        $raw = (string) Mage::getStoreConfig(self::PATH_QUEUES_LIST);
        $names = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if (count($names) === 0) {
            $names = ['default'];
        }
        return array_values(array_unique($names));
    }

    public function getRetryMaxAttempts(?int $storeId = null): int
    {
        return (int) Mage::getStoreConfig(self::PATH_OPERATIONAL_PREFIX . 'retry_max_attempts', $storeId) ?: 3;
    }

    public function getRetryBackoffBaseSeconds(?int $storeId = null): int
    {
        return (int) Mage::getStoreConfig(self::PATH_OPERATIONAL_PREFIX . 'retry_backoff_base_seconds', $storeId) ?: 5;
    }

    public function getRetryBackoffCapSeconds(?int $storeId = null): int
    {
        return (int) Mage::getStoreConfig(self::PATH_OPERATIONAL_PREFIX . 'retry_backoff_cap_seconds', $storeId) ?: 3600;
    }

    public function getPayloadMaxBytes(?int $storeId = null): int
    {
        return (int) Mage::getStoreConfig(self::PATH_OPERATIONAL_PREFIX . 'payload_max_bytes', $storeId) ?: 262144;
    }

    /**
     * @return list<string>
     */
    public function getRedactedFields(?int $storeId = null): array
    {
        $raw = (string) Mage::getStoreConfig(self::PATH_OPERATIONAL_PREFIX . 'redacted_fields', $storeId);
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    public function isAuditAdminActionsEnabled(?int $storeId = null): bool
    {
        return (bool) Mage::getStoreConfigFlag(self::PATH_OPERATIONAL_PREFIX . 'audit_admin_actions', $storeId);
    }

    public function getSuccessRetentionDays(?int $storeId = null): int
    {
        return (int) Mage::getStoreConfig(self::PATH_RETENTION_PREFIX . 'success_retention_days', $storeId) ?: 7;
    }

    public function getFailureRetentionDays(?int $storeId = null): int
    {
        return (int) Mage::getStoreConfig(self::PATH_RETENTION_PREFIX . 'failure_retention_days', $storeId) ?: 30;
    }

    public function getArchiveRetentionDays(?int $storeId = null): int
    {
        return (int) Mage::getStoreConfig(self::PATH_RETENTION_PREFIX . 'archive_retention_days', $storeId) ?: 1;
    }

    public function getArchiveBatchSize(?int $storeId = null): int
    {
        return (int) Mage::getStoreConfig(self::PATH_RETENTION_PREFIX . 'archive_batch_size', $storeId) ?: 1000;
    }

    public function getAuditRetentionDays(?int $storeId = null): int
    {
        return (int) Mage::getStoreConfig(self::PATH_RETENTION_PREFIX . 'audit_retention_days', $storeId) ?: 90;
    }

    private function decryptIfNotEmpty(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }
        return (string) Mage::helper('core')->decrypt($encrypted);
    }
}
