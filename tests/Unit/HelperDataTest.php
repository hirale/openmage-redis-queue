<?php

declare(strict_types=1);

namespace HiraleQueue\Tests\Unit;

use Hirale_Queue_Helper_Data;
use Mage;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use ReflectionProperty;

class HelperDataTest extends TestCase
{
    protected function setUp(): void
    {
        Mage::resetTestState();
        $this->resetRedisSingleton();
    }

    protected function tearDown(): void
    {
        $this->resetRedisSingleton();
    }

    public function testDsnTakesPrecedenceOverLegacyConnectionFields(): void
    {
        Mage::setStoreConfig('hirale_queue/settings/dsn', 'redis://redis.example.test:6380/7');
        Mage::setStoreConfig('system/hirale_queue/scheme', 'tcp');
        Mage::setStoreConfig('system/hirale_queue/host', 'legacy.example.test');
        Mage::setStoreConfig('system/hirale_queue/port', '6379');
        Mage::setStoreConfig('system/hirale_queue/database', '1');

        $client = (new Hirale_Queue_Helper_Data())->getRedis();
        $parameters = $client->getConnection()->getParameters();

        $this->assertInstanceOf(Client::class, $client);
        $this->assertSame('redis.example.test', (string) $parameters->host);
        $this->assertSame(6380, (int) $parameters->port);
        $this->assertSame(7, (int) $parameters->database);
    }

    public function testTcpConnectionFieldsComposeToDsnWhenDsnIsEmpty(): void
    {
        Mage::setStoreConfig('hirale_queue/settings/dsn', '');
        Mage::setStoreConfig('system/hirale_queue/scheme', 'tcp');
        Mage::setStoreConfig('system/hirale_queue/host', 'legacy.example.test');
        Mage::setStoreConfig('system/hirale_queue/port', '6381');
        Mage::setStoreConfig('system/hirale_queue/database', '2');

        $this->assertSame('redis://legacy.example.test:6381/2', (new Hirale_Queue_Helper_Data())->getRedisDsn());
    }

    public function testUnixConnectionFieldsDoNotComposeToTcpDsn(): void
    {
        Mage::setStoreConfig('system/hirale_queue/scheme', 'unix');
        Mage::setStoreConfig('system/hirale_queue/host', '/tmp/redis.sock');
        Mage::setStoreConfig('system/hirale_queue/database', '3');

        $this->assertSame('', (new Hirale_Queue_Helper_Data())->getRedisDsn());
    }

    public function testLegacyTcpConnectionFieldsStillBuildAClientWhenNewPathIsEmpty(): void
    {
        Mage::setStoreConfig('system/hirale_queue/scheme', 'tcp');
        Mage::setStoreConfig('system/hirale_queue/host', 'legacy.example.test');
        Mage::setStoreConfig('system/hirale_queue/port', '6381');
        Mage::setStoreConfig('system/hirale_queue/database', '2');

        $parameters = (new Hirale_Queue_Helper_Data())->getRedis()->getConnection()->getParameters();

        $this->assertSame('redis', (string) $parameters->scheme);
        $this->assertSame('legacy.example.test', (string) $parameters->host);
        $this->assertSame(6381, (int) $parameters->port);
        $this->assertSame(2, (int) $parameters->database);
    }

    public function testLegacyUnixConnectionFieldBuildsSocketClient(): void
    {
        Mage::setStoreConfig('system/hirale_queue/scheme', 'unix');
        Mage::setStoreConfig('system/hirale_queue/host', '/tmp/redis.sock');
        Mage::setStoreConfig('system/hirale_queue/database', '3');

        $parameters = (new Hirale_Queue_Helper_Data())->getRedis()->getConnection()->getParameters();

        $this->assertSame('unix', (string) $parameters->scheme);
        $this->assertSame('/tmp/redis.sock', (string) $parameters->path);
        $this->assertSame(3, (int) $parameters->database);
    }

    public function testConfigValuePrefersNewPathOverLegacyPath(): void
    {
        Mage::setStoreConfig('hirale_queue/settings/count', '25');
        Mage::setStoreConfig('system/hirale_queue/count', '10');

        $this->assertSame('25', (new Hirale_Queue_Helper_Data())->getConfigValue('count', 1));
    }

    public function testConfigFlagFallsBackToLegacyPath(): void
    {
        Mage::setStoreConfig('system/hirale_queue/enabled', '1');

        $this->assertTrue((new Hirale_Queue_Helper_Data())->getConfigFlag('enabled'));
    }

    private function resetRedisSingleton(): void
    {
        $reflection = new ReflectionProperty(Hirale_Queue_Helper_Data::class, '_redis');
        $reflection->setValue(null, null);
    }
}
