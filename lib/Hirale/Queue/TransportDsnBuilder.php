<?php

declare(strict_types=1);

namespace Hirale\Queue;

use InvalidArgumentException;

/**
 * Assembles backend-specific DSN strings from the admin form config.
 *
 * Pure logic — no Magento dependencies. The Helper reads the config array
 * from Mage::getStoreConfig and passes it in. Each backend gets a single
 * connection; per-queue routing is encoded as a stream/queue name parameter
 * on the DSN. Symfony's TransportFactoryLocator picks the right factory
 * from the DSN scheme.
 *
 * Unix socket support (Redis backend): if the host begins with '/', the DSN
 * uses Symfony's `host[]` array parameter so ext-redis connects via the socket.
 */
final class TransportDsnBuilder
{
    /**
     * @param array<string, mixed> $backendConfig
     */
    public function build(array $backendConfig, string $queueName): string
    {
        $type = (string) ($backendConfig['type'] ?? '');
        return match ($type) {
            'redis'    => $this->buildRedisDsn($backendConfig, $queueName),
            'doctrine' => $this->buildDoctrineDsn($backendConfig, $queueName),
            'amqp'     => $this->buildAmqpDsn($backendConfig, $queueName),
            'sqs'      => $this->buildSqsDsn($backendConfig, $queueName),
            default    => throw new InvalidArgumentException("Unknown backend type: {$type}"),
        };
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function buildRedisDsn(array $cfg, string $queueName): string
    {
        $host          = (string) ($cfg['redis_host'] ?? '');
        $port          = (int) ($cfg['redis_port'] ?? 6379);
        $password      = (string) ($cfg['redis_password'] ?? '');
        $database      = (int) ($cfg['redis_database'] ?? 0);
        $useTls        = (bool) ($cfg['redis_use_tls'] ?? false);
        $tlsVerifyPeer = (bool) ($cfg['redis_tls_verify_peer'] ?? true);
        $tlsCaFile     = (string) ($cfg['redis_tls_cafile'] ?? '');

        if ($host === '') {
            throw new InvalidArgumentException('Redis host is required.');
        }

        $query = [
            'stream'  => $queueName,
            'group'   => 'hirale_queue',
            'dbindex' => $database,
        ];
        if ($password !== '') {
            $query['auth'] = $password;
        }

        // Unix socket: Symfony Messenger Redis transport uses host[] array form.
        // TLS does not apply to socket connections.
        if ($host[0] === '/') {
            $query['host'] = [$host => 1];
            return 'redis://default@?' . http_build_query($query);
        }

        // The transport enables TLS purely off the rediss:// scheme; stream
        // context options ride in the nested ssl[] query array (see
        // symfony/redis-messenger Connection: $options['ssl'], php.net/context.ssl).
        $scheme = $useTls ? 'rediss' : 'redis';
        if ($useTls) {
            if (!$tlsVerifyPeer) {
                $query['ssl'] = ['verify_peer' => '0', 'verify_peer_name' => '0'];
            }
            if ($tlsCaFile !== '') {
                $query['ssl']['cafile'] = $tlsCaFile;
            }
        }

        return sprintf('%s://%s:%d?%s', $scheme, $host, $port, http_build_query($query));
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function buildDoctrineDsn(array $cfg, string $queueName): string
    {
        $connection = (string) ($cfg['doctrine_connection'] ?? 'core_write');
        $tableName  = (string) ($cfg['doctrine_table_name'] ?? 'hirale_queue_messages');
        $autoSetup  = (bool) ($cfg['doctrine_auto_setup'] ?? true);

        $query = [
            'queue_name' => $queueName,
            'table_name' => $tableName,
            'auto_setup' => $autoSetup ? '1' : '0',
        ];
        return sprintf('doctrine://%s?%s', $connection, http_build_query($query));
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function buildAmqpDsn(array $cfg, string $queueName): string
    {
        $host     = (string) ($cfg['amqp_host'] ?? '');
        $port     = (int) ($cfg['amqp_port'] ?? 5672);
        $user     = (string) ($cfg['amqp_user'] ?? 'guest');
        $password = (string) ($cfg['amqp_password'] ?? 'guest');
        $vhost    = (string) ($cfg['amqp_vhost'] ?? '/');
        $exchange = (string) ($cfg['amqp_exchange'] ?? 'hirale_queue');

        if ($host === '') {
            throw new InvalidArgumentException('AMQP host is required.');
        }

        // Encode vhost path component (e.g., '/' becomes '%2f').
        $encodedVhost = rawurlencode(ltrim($vhost, '/'));
        $query = [
            'exchange[name]' => $exchange,
            'exchange[type]' => 'direct',
            'queues[' . $queueName . '][binding_keys][0]' => $queueName,
        ];
        return sprintf(
            'amqp://%s:%s@%s:%d/%s?%s',
            rawurlencode($user),
            rawurlencode($password),
            $host,
            $port,
            $encodedVhost,
            http_build_query($query),
        );
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function buildSqsDsn(array $cfg, string $queueName): string
    {
        $region              = (string) ($cfg['sqs_region'] ?? '');
        $queueUrlPrefix      = (string) ($cfg['sqs_queue_url'] ?? '');
        $useInstanceProfile  = (bool) ($cfg['sqs_use_instance_profile'] ?? false);
        $accessKey           = (string) ($cfg['sqs_access_key'] ?? '');
        $secretKey           = (string) ($cfg['sqs_secret_key'] ?? '');

        if ($region === '' || $queueUrlPrefix === '') {
            throw new InvalidArgumentException('SQS region and queue URL prefix are required.');
        }

        $queueUrl = rtrim($queueUrlPrefix, '/') . '/' . $queueName;
        $query = ['region' => $region];
        if (!$useInstanceProfile) {
            if ($accessKey === '' || $secretKey === '') {
                throw new InvalidArgumentException('SQS access key and secret key are required when instance profile is disabled.');
            }
            $query['access_key'] = $accessKey;
            $query['secret_key'] = $secretKey;
        }
        return sprintf('https+sqs://%s?%s', $queueUrl, http_build_query($query));
    }
}
