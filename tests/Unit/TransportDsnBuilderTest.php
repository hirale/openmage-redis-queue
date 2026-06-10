<?php

declare(strict_types=1);

namespace HiraleQueue\Tests\Unit;

use Hirale\Queue\TransportDsnBuilder;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class TransportDsnBuilderTest extends TestCase
{
    private TransportDsnBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new TransportDsnBuilder();
    }

    public function testRedisTcpDsnHasStreamAndGroup(): void
    {
        $dsn = $this->builder->build([
            'type'                  => 'redis',
            'redis_host'            => 'redis.internal',
            'redis_port'            => 6380,
            'redis_password'        => '',
            'redis_database'        => 3,
            'redis_use_tls'         => false,
            'redis_tls_verify_peer' => false,
            'redis_tls_cafile'      => '',
        ], 'analytics');

        self::assertStringStartsWith('redis://redis.internal:6380?', $dsn);
        self::assertStringContainsString('stream=analytics', $dsn);
        self::assertStringContainsString('group=hirale_queue', $dsn);
        self::assertStringContainsString('dbindex=3', $dsn);
    }

    public function testRedisPlainSchemeEvenWhenVerifyPeerEnabled(): void
    {
        // Regression: verify_peer defaults to Yes and must NOT imply TLS —
        // only the explicit use_tls flag switches the scheme to rediss.
        $dsn = $this->builder->build([
            'type'                  => 'redis',
            'redis_host'            => '127.0.0.1',
            'redis_port'            => 6379,
            'redis_password'        => '',
            'redis_database'        => 0,
            'redis_tls_verify_peer' => true,
            'redis_tls_cafile'      => '',
        ], 'default');

        self::assertStringStartsWith('redis://127.0.0.1:6379?', $dsn);
        self::assertStringNotContainsString('ssl', $dsn);
    }

    public function testRedisRedissSchemeWithCaFileWhenTlsEnabled(): void
    {
        $dsn = $this->builder->build([
            'type'                  => 'redis',
            'redis_host'            => 'redis.internal',
            'redis_port'            => 6379,
            'redis_password'        => '',
            'redis_database'        => 0,
            'redis_use_tls'         => true,
            'redis_tls_verify_peer' => true,
            'redis_tls_cafile'      => '/etc/ssl/certs/ca.pem',
        ], 'default');

        self::assertStringStartsWith('rediss://', $dsn);
        // Stream context options ride in the nested ssl[] array.
        self::assertStringContainsString('ssl%5Bcafile%5D=', $dsn);
        self::assertStringNotContainsString('ssl%5Bverify_peer%5D', $dsn);
    }

    public function testRedisTlsWithoutPeerVerificationDisablesBothChecks(): void
    {
        $dsn = $this->builder->build([
            'type'                  => 'redis',
            'redis_host'            => 'redis.internal',
            'redis_port'            => 6379,
            'redis_password'        => '',
            'redis_database'        => 0,
            'redis_use_tls'         => true,
            'redis_tls_verify_peer' => false,
            'redis_tls_cafile'      => '',
        ], 'default');

        self::assertStringStartsWith('rediss://', $dsn);
        self::assertStringContainsString('ssl%5Bverify_peer%5D=0', $dsn);
        self::assertStringContainsString('ssl%5Bverify_peer_name%5D=0', $dsn);
    }

    public function testRedisUnixSocketEncodedAsHostArray(): void
    {
        $dsn = $this->builder->build([
            'type'                  => 'redis',
            'redis_host'            => '/var/run/redis/redis.sock',
            'redis_port'            => 0,
            'redis_password'        => 'secret',
            'redis_database'        => 0,
            'redis_tls_verify_peer' => false,
            'redis_tls_cafile'      => '',
        ], 'default');

        self::assertStringStartsWith('redis://default@?', $dsn);
        // ext-redis-style host[] array
        self::assertStringContainsString('host%5B%2Fvar%2Frun%2Fredis%2Fredis.sock%5D=1', $dsn);
        self::assertStringContainsString('auth=secret', $dsn);
    }

    public function testDoctrineDsnHasQueueAndTableName(): void
    {
        $dsn = $this->builder->build([
            'type'                => 'doctrine',
            'doctrine_connection' => 'core_write',
            'doctrine_table_name' => 'hirale_queue_messages',
            'doctrine_auto_setup' => true,
        ], 'default');

        self::assertStringStartsWith('doctrine://core_write?', $dsn);
        self::assertStringContainsString('queue_name=default', $dsn);
        self::assertStringContainsString('table_name=hirale_queue_messages', $dsn);
        self::assertStringContainsString('auto_setup=1', $dsn);
    }

    public function testAmqpDsnEncodesAllCredentialsAndQueueBinding(): void
    {
        $dsn = $this->builder->build([
            'type'          => 'amqp',
            'amqp_host'     => 'rabbit.internal',
            'amqp_port'     => 5672,
            'amqp_user'     => 'queue user',
            'amqp_password' => 'pa$$word',
            'amqp_vhost'    => '/prod',
            'amqp_exchange' => 'hirale_queue',
        ], 'orders');

        self::assertStringStartsWith('amqp://queue%20user:pa%24%24word@rabbit.internal:5672/prod?', $dsn);
        self::assertStringContainsString('exchange%5Bname%5D=hirale_queue', $dsn);
        self::assertStringContainsString('queues%5Borders%5D%5Bbinding_keys%5D%5B0%5D=orders', $dsn);
    }

    public function testSqsDsnWithAccessKey(): void
    {
        $dsn = $this->builder->build([
            'type'                     => 'sqs',
            'sqs_region'               => 'us-east-1',
            'sqs_queue_url'            => 'https://sqs.us-east-1.amazonaws.com/123456/hirale',
            'sqs_use_instance_profile' => false,
            'sqs_access_key'           => 'AKIA...',
            'sqs_secret_key'           => 'verysecret',
        ], 'default');

        self::assertStringStartsWith('https+sqs://https://sqs.us-east-1.amazonaws.com/123456/hirale/default?', $dsn);
        self::assertStringContainsString('access_key=AKIA', $dsn);
        self::assertStringContainsString('secret_key=verysecret', $dsn);
    }

    public function testSqsInstanceProfileOmitsExplicitKeys(): void
    {
        $dsn = $this->builder->build([
            'type'                     => 'sqs',
            'sqs_region'               => 'us-east-1',
            'sqs_queue_url'            => 'https://sqs.example/queue',
            'sqs_use_instance_profile' => true,
            'sqs_access_key'           => '',
            'sqs_secret_key'           => '',
        ], 'default');

        self::assertStringContainsString('region=us-east-1', $dsn);
        self::assertStringNotContainsString('access_key=', $dsn);
        self::assertStringNotContainsString('secret_key=', $dsn);
    }

    public function testUnknownBackendThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->build(['type' => 'kafka'], 'default');
    }

    public function testRedisRequiresHost(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->builder->build(['type' => 'redis'], 'default');
    }
}
