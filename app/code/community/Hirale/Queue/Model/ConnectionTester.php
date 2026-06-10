<?php

/**
 * Shared backend-connection validation used by both the save-time observer and
 * the Test Connection button in System Configuration. Parses the raw admin
 * form values (groups[backend][fields][...][value]) into the same config array
 * shape Helper::getBackendConfig() produces, then probes the backend.
 *
 * Package availability is checked the same way Maho core checks mailer
 * bridges: \Composer\InstalledVersions::isInstalled() (see
 * Mage_Adminhtml_Model_System_Config_Source_Email_Transport).
 */
class Hirale_Queue_Model_ConnectionTester
{
    /**
     * Composer package required per backend. Redis is absent on purpose —
     * symfony/redis-messenger and ext-redis are hard requires of this module.
     */
    public const REQUIRED_PACKAGES = [
        Hirale_Queue_Helper_Data::BACKEND_DOCTRINE => 'symfony/doctrine-messenger',
        Hirale_Queue_Helper_Data::BACKEND_AMQP     => 'symfony/amqp-messenger',
        Hirale_Queue_Helper_Data::BACKEND_SQS      => 'symfony/amazon-sqs-messenger',
    ];

    private const BACKEND_LABELS = [
        Hirale_Queue_Helper_Data::BACKEND_REDIS    => 'Redis',
        Hirale_Queue_Helper_Data::BACKEND_DOCTRINE => 'Doctrine (database)',
        Hirale_Queue_Helper_Data::BACKEND_AMQP     => 'AMQP / RabbitMQ',
        Hirale_Queue_Helper_Data::BACKEND_SQS      => 'Amazon SQS',
    ];

    /**
     * @param array<string, mixed> $groups Raw groups[] param from the system config form.
     * @return array<string, mixed> Same shape as Helper::getBackendConfig().
     */
    public function buildBackendConfigFromForm(array $groups): array
    {
        $get = static function (string $group, string $field) use ($groups) {
            return $groups[$group]['fields'][$field]['value'] ?? null;
        };

        $type = (string) ($get('backend', 'type') ?? Hirale_Queue_Helper_Data::BACKEND_REDIS);
        $cfg  = ['type' => $type];

        switch ($type) {
            case Hirale_Queue_Helper_Data::BACKEND_REDIS:
                $cfg += [
                    'redis_host'            => (string) ($get('backend', 'redis_host') ?? ''),
                    'redis_port'            => (int) ($get('backend', 'redis_port') ?? 6379),
                    'redis_password'        => $this->formSecret($get('backend', 'redis_password'), 'redis_password'),
                    'redis_database'        => (int) ($get('backend', 'redis_database') ?? 0),
                    'redis_use_tls'         => (bool) ($get('backend', 'redis_use_tls') ?? false),
                    'redis_tls_verify_peer' => (bool) ($get('backend', 'redis_tls_verify_peer') ?? true),
                    'redis_tls_cafile'      => (string) ($get('backend', 'redis_tls_cafile') ?? ''),
                ];
                break;
            case Hirale_Queue_Helper_Data::BACKEND_DOCTRINE:
                $cfg += [
                    'doctrine_connection' => (string) ($get('backend', 'doctrine_connection') ?? 'core_write'),
                    'doctrine_table_name' => (string) ($get('backend', 'doctrine_table_name') ?? 'hirale_queue_messages'),
                    'doctrine_auto_setup' => (bool) ($get('backend', 'doctrine_auto_setup') ?? true),
                ];
                break;
            case Hirale_Queue_Helper_Data::BACKEND_AMQP:
                $cfg += [
                    'amqp_host'     => (string) ($get('backend', 'amqp_host') ?? ''),
                    'amqp_port'     => (int) ($get('backend', 'amqp_port') ?? 5672),
                    'amqp_user'     => (string) ($get('backend', 'amqp_user') ?? ''),
                    'amqp_password' => $this->formSecret($get('backend', 'amqp_password'), 'amqp_password'),
                    'amqp_vhost'    => (string) ($get('backend', 'amqp_vhost') ?? '/'),
                    'amqp_exchange' => (string) ($get('backend', 'amqp_exchange') ?? 'hirale_queue'),
                ];
                break;
            case Hirale_Queue_Helper_Data::BACKEND_SQS:
                $cfg += [
                    'sqs_region'               => (string) ($get('backend', 'sqs_region') ?? ''),
                    'sqs_queue_url'            => (string) ($get('backend', 'sqs_queue_url') ?? ''),
                    'sqs_use_instance_profile' => (bool) ($get('backend', 'sqs_use_instance_profile') ?? false),
                    'sqs_access_key'           => $this->formSecret($get('backend', 'sqs_access_key'), 'sqs_access_key'),
                    'sqs_secret_key'           => $this->formSecret($get('backend', 'sqs_secret_key'), 'sqs_secret_key'),
                ];
                break;
        }
        return $cfg;
    }

    /**
     * @param array<string, mixed> $groups
     * @return list<string>
     */
    public function parseQueueListFromForm(array $groups): array
    {
        $raw = (string) ($groups['queues']['fields']['list']['value'] ?? '');
        $names = array_values(array_filter(array_map('trim', explode(',', $raw))));
        return $names === [] ? ['default'] : $names;
    }

    /**
     * Probe the backend; throws with an operator-actionable message on failure.
     *
     * Non-strict (save-time): missing-package and extension checks only for
     * backends that are not wired yet — the save is allowed so the operator
     * can stage config, and the first dispatch throws a clear error.
     * Strict (Test Connection button): additionally reports not-yet-wired
     * backends as failures, because that is exactly what dispatch would do.
     *
     * @param array<string, mixed> $backendCfg
     */
    public function probe(array $backendCfg, bool $strict = false): void
    {
        $type = (string) ($backendCfg['type'] ?? '');
        switch ($type) {
            case Hirale_Queue_Helper_Data::BACKEND_REDIS:
                $this->probeRedis($backendCfg);
                return;
            case Hirale_Queue_Helper_Data::BACKEND_DOCTRINE:
            case Hirale_Queue_Helper_Data::BACKEND_AMQP:
            case Hirale_Queue_Helper_Data::BACKEND_SQS:
                $this->assertPackageInstalled($type);
                if ($type === Hirale_Queue_Helper_Data::BACKEND_AMQP && !extension_loaded('amqp')) {
                    throw new \RuntimeException(
                        'The AMQP / RabbitMQ backend requires the amqp PHP extension (ext-amqp).',
                    );
                }
                if ($strict) {
                    throw new \RuntimeException(sprintf(
                        '%s support is not wired in v3.0 yet — only the Redis backend can dispatch and consume. The required package is installed; this backend will activate in a future release.',
                        self::BACKEND_LABELS[$type],
                    ));
                }
                return;
            default:
                throw new \RuntimeException(sprintf('Unknown backend type "%s".', $type));
        }
    }

    private function assertPackageInstalled(string $type): void
    {
        $package = self::REQUIRED_PACKAGES[$type] ?? null;
        if ($package === null || \Composer\InstalledVersions::isInstalled($package)) {
            return;
        }
        throw new \RuntimeException(sprintf(
            'The %s backend requires the "%s" package. Run: composer require %s',
            self::BACKEND_LABELS[$type] ?? $type,
            $package,
            $package,
        ));
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function probeRedis(array $cfg): void
    {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException('ext-redis is not loaded.');
        }
        $host    = (string) ($cfg['redis_host'] ?? '');
        $port    = (int) ($cfg['redis_port'] ?? 6379);
        $passwd  = (string) ($cfg['redis_password'] ?? '');
        $db      = (int) ($cfg['redis_database'] ?? 0);
        $useTls  = (bool) ($cfg['redis_use_tls'] ?? false);
        if ($host === '') {
            throw new \RuntimeException('Redis host is required.');
        }
        $redis = new \Redis();
        // Connect with a short timeout — neither admin save nor the Test
        // Connection button should hang for a minute. TLS settings mirror what
        // the transport DSN will use, so a passing probe means dispatch works.
        if ($host[0] === '/') {
            $connected = $redis->connect($host, 0, 3.0);
        } elseif ($useTls) {
            $verify = (bool) ($cfg['redis_tls_verify_peer'] ?? true);
            $stream = ['verify_peer' => $verify, 'verify_peer_name' => $verify];
            $cafile = (string) ($cfg['redis_tls_cafile'] ?? '');
            if ($cafile !== '') {
                $stream['cafile'] = $cafile;
            }
            $connected = $redis->connect('tls://' . $host, $port, 3.0, null, 0, 0, ['stream' => $stream]);
        } else {
            $connected = $redis->connect($host, $port, 3.0);
        }
        if (!$connected) {
            throw new \RuntimeException(sprintf('Could not connect to %s.', $host));
        }
        if ($passwd !== '' && !$redis->auth($passwd)) {
            throw new \RuntimeException('AUTH failed.');
        }
        if ($db !== 0 && !$redis->select($db)) {
            throw new \RuntimeException(sprintf('Could not select db %d.', $db));
        }
        $pong = $redis->ping();
        if ($pong !== '+PONG' && $pong !== true && $pong !== 'PONG') {
            throw new \RuntimeException('PING did not return PONG.');
        }
        $redis->close();
    }

    /**
     * Admin form sends the literal string "******" for unchanged obscure
     * fields. Fall back to the stored value (decrypted) so the probe runs with
     * the credentials that will actually be in effect.
     */
    private function formSecret(mixed $value, string $field): string
    {
        if (!is_string($value) || $value === '') {
            return '';
        }
        if (preg_match('/^\*+$/', $value) === 1) {
            return $this->savedSecret($field);
        }
        return $value;
    }

    private function savedSecret(string $field): string
    {
        $stored = (string) Mage::getStoreConfig('hirale_queue/backend/' . $field);
        if ($stored === '') {
            return '';
        }
        return (string) Mage::helper('core')->decrypt($stored);
    }
}
