<?php
use Predis\Client;

class Hirale_Queue_Helper_Data extends Mage_Core_Helper_Abstract
{
    private static $_redis;


    /**
     * Retrieves the Redis client instance.
     *
     * This function checks if the Redis client instance has already been created.
     * If it has not, it retrieves the Redis configuration from the Magento system configuration.
     * The configuration includes the host, scheme, port, and database.
     * It then creates a new Redis client instance using the retrieved configuration.
     *
     * @return Client The Redis client instance.
     */

    public function getRedis()
    {
        if (!self::$_redis) {
            $host = Mage::getStoreConfig('system/hirale_queue/host') ?: 'localhost';
            $scheme = Mage::getStoreConfig('system/hirale_queue/scheme') ?: 'tcp';
            $port = Mage::getStoreConfig('system/hirale_queue/port') ?: 6379;
            $database = Mage::getStoreConfig('system/hirale_queue/database') ?: 0;

            self::$_redis = new Client([
                'scheme' => $scheme,
                'host' => $host,
                'port' => $port,
                'database' => $database
            ]);
        }

        return self::$_redis;
    }
}
