<?php

declare(strict_types=1);

use Predis\Client;

class Hirale_Queue_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const XML_PATH_PREFIX = 'hirale_queue/settings/';
    public const XML_PATH_LEGACY_PREFIX = 'system/hirale_queue/';

    private static ?Client $_redis = null;

    /**
     * Return the shared Predis client for queue operations.
     *
     * A configured DSN is preferred because it works consistently across Maho
     * and OpenMage installations. When no DSN is configured, the legacy
     * scheme/host/port/database fields are composed into an equivalent runtime
     * DSN for TCP connections and kept as Predis parameters for unix sockets.
     */
    public function getRedis(): Client
    {
        if (self::$_redis === null) {
            $dsn = $this->getRedisDsn();
            if ($dsn !== '') {
                self::$_redis = new Client($dsn);
                return self::$_redis;
            }

            self::$_redis = new Client($this->getRedisConnectionParams());
        }

        return self::$_redis;
    }

    public function getRedisDsn(): string
    {
        $dsn = trim((string) $this->getConfigValue('dsn', ''));
        if ($dsn !== '') {
            return $dsn;
        }

        $scheme = (string) ($this->getConfigValue('scheme', 'tcp') ?: 'tcp');
        if ($scheme === 'unix') {
            return '';
        }

        $host = (string) ($this->getConfigValue('host', 'localhost') ?: 'localhost');
        $port = (int) ($this->getConfigValue('port', 6379) ?: 6379);
        $database = (int) ($this->getConfigValue('database', 0) ?: 0);
        $dsnScheme = $scheme === 'tcp' ? 'redis' : $scheme;

        if (str_contains($host, ':') && $host[0] !== '[') {
            $host = '[' . $host . ']';
        }

        return sprintf('%s://%s:%d/%d', $dsnScheme, $host, $port, $database);
    }

    /**
     * @return array<string, mixed>
     */
    private function getRedisConnectionParams(): array
    {
        $scheme = (string) ($this->getConfigValue('scheme', 'tcp') ?: 'tcp');
        $params = [
            'scheme' => $scheme,
            'database' => (int) ($this->getConfigValue('database', 0) ?: 0),
        ];

        if ($scheme === 'unix') {
            $params['path'] = $this->getConfigValue('host', '/var/run/redis/redis.sock') ?: '/var/run/redis/redis.sock';
        } else {
            $params['host'] = $this->getConfigValue('host', 'localhost') ?: 'localhost';
            $params['port'] = (int) ($this->getConfigValue('port', 6379) ?: 6379);
        }

        return $params;
    }

    /**
     * Read the new Hirale > Queue config path with a legacy System fallback.
     *
     * The setup migration copies saved values forward, but this fallback keeps
     * deployments with file/env-level legacy config overrides working.
     *
     * @param mixed $default
     * @return mixed
     */
    public function getConfigValue(string $field, $default = null)
    {
        $value = Mage::getStoreConfig(self::XML_PATH_PREFIX . $field);
        if ($value !== null && $value !== '') {
            return $value;
        }

        $legacyValue = Mage::getStoreConfig(self::XML_PATH_LEGACY_PREFIX . $field);
        if ($legacyValue !== null && $legacyValue !== '') {
            return $legacyValue;
        }

        return $default;
    }

    public function getConfigFlag(string $field): bool
    {
        $value = $this->getConfigValue($field, false);
        $value = is_string($value) ? strtolower($value) : $value;

        return !empty($value) && $value !== 'false';
    }
}
