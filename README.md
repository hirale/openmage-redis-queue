# Hirale Redis Queue Module

A community-scope queue module for OpenMage and Maho. Version 2.x uses Redis
Streams for worker delivery and a database-backed job index for observability,
manual recovery, and audit history.

## Modules that depend on this module

| Module | URL |
| --- | --- |
| Google Analytics 4 Measurement Protocol | [openmage-ga4-measurement](https://github.com/hirale/openmage-ga4-measurement) |
| Meta Conversions API | [openmage-meta-conversions](https://github.com/hirale/openmage-meta-conversions) |

## Install

## Compatibility

| Module line | Platform support | Logger API |
| --- | --- | --- |
| 2.x | Maho 25.9+ or OpenMage 20.17+ | Monolog-backed `Mage::log()` |
| Legacy 1.x | Older Maho/OpenMage/Magento 1 installations | Zend_Log-backed `Mage::log()` |

The current module line declares Composer conflicts for `mahocommerce/maho <25.9`
and `openmage/magento-lts <20.17`, because this code uses Monolog log levels.
Keep older installations pinned to the legacy 1.x module line.

### Install with Composer

```bash
composer require hirale/openmage-redis-queue
```

The package is installed in the `community` code pool so projects can override or extend behavior from the `local` code pool.

Maho projects satisfy `magento-hackathon/magento-composer-installer` through `mahocommerce/maho`'s Composer `replace` rule and load the mapped files through the Maho Composer plugin. OpenMage projects install the Magento Composer Installer and apply the same `extra.map` into `app/code/community`.

Runtime platform differences are isolated behind `hirale_queue/platform_factory`; queue handlers should depend on shared queue APIs and only use the platform adapter for behavior that differs between Maho and OpenMage.

From version 1.1.0, admin configuration moved from
`System > Configuration > System > Hirale Redis Queue Settings` to
`System > Configuration > Hirale > Queue`. The setup migration copies saved
values from `system/hirale_queue/*` to `hirale_queue/settings/*` without
overwriting values already saved at the new paths.

Version 2.0.0 adds the `hirale_queue_job` table. Redis remains the execution
queue, while the database stores job status, retry scheduling, last errors, and
admin history. Existing Redis stream messages are not deleted during upgrade;
when a 2.x worker consumes a legacy message it creates a matching DB job record
before running the handler.

## Development

Install dev dependencies and run the package test suite:

```bash
composer update
composer test
```

The test suite uses PHPUnit with local Mage test doubles, so it does not need a
running Maho/OpenMage application or Redis server.

## Usage

### Setup

Go to system config `System > Configuration > Hirale > Queue`.

![System > Configuration > System > Hirale Redis Queue Settings](image.png)

The queue worker is disabled by default. Configure Redis, use **Test Redis
Connection** from `System > Tools > Hirale Queue`, then enable the worker and
make sure cron is running.

### Consumer modes

You can consume queued jobs in either mode:

| Mode | Command | Best for |
| --- | --- | --- |
| Cron | Existing platform cron runs `hirale_queue/task::process` every minute | Simple installs, low volume, delay-tolerant work |
| Long-running worker | OpenMage: `php shell/hirale_queue_worker.php`; Maho: `./maho hirale:queue:work` | Production queues, lower latency, multiple workers |

Cron mode is the default compatibility path. It uses the module's existing
`hirale_queue_process` cron job and does not require another process manager.

Long-running worker mode keeps a foreground CLI process alive and repeatedly
reads Redis Streams. Run it under systemd or Supervisor so the process is
restarted after deploys, crashes, or its configured runtime limit.

OpenMage/Magento shell worker:

```bash
php shell/hirale_queue_worker.php --consumer=hirale_queue_01 --max-runtime=3600 --max-jobs=10000
```

Maho CLI worker:

```bash
./maho hirale:queue:work --consumer=hirale_queue_01 --max-runtime=3600 --max-jobs=10000
```

If the Maho command is not listed after install or update, run
`composer dump-autoload`, then verify it with `./maho list | grep hirale`.

Available worker options:

| Option | Default | Description |
| --- | --- | --- |
| `--consumer` | Generated from host and PID | Redis consumer name. Use a unique name per long-running worker. |
| `--count` | System config `count` | Messages to read per batch. |
| `--publish-limit` | System config `publish_limit` | Due DB jobs to publish before each worker read. |
| `--max-runtime` | `3600` | Exit after this many seconds so the process manager can restart a fresh PHP process. |
| `--max-jobs` | `10000` | Exit after this many processed jobs. |
| `--idle-sleep` | `1` | Sleep seconds after an empty batch. |
| `--once` | Off | Process one batch and exit. Useful for smoke tests. |

Do not run multiple long-lived workers with the same `--consumer` name. Redis
consumer groups use the consumer name to track pending work.

#### systemd example

OpenMage template unit, for example `/etc/systemd/system/hirale-queue@.service`:

```ini
[Unit]
Description=Hirale Redis Queue worker %i
After=network.target

[Service]
Type=simple
WorkingDirectory=/var/www/openmage
User=www-data
ExecStart=/usr/bin/php shell/hirale_queue_worker.php --consumer=hirale_queue_%i --max-runtime=3600 --max-jobs=10000
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
```

Maho template unit, for example `/etc/systemd/system/hirale-queue@.service`:

```ini
[Unit]
Description=Hirale Redis Queue worker %i
After=network.target

[Service]
Type=simple
WorkingDirectory=/var/www/maho
User=www-data
ExecStart=/usr/bin/php ./maho hirale:queue:work --consumer=hirale_queue_%i --max-runtime=3600 --max-jobs=10000
Restart=always
RestartSec=5
KillSignal=SIGTERM
TimeoutStopSec=60

[Install]
WantedBy=multi-user.target
```

Example operations:

```bash
sudo systemctl enable --now hirale-queue@01
sudo systemctl enable --now hirale-queue@02
```

#### Supervisor example

```ini
[program:hirale-queue]
directory=/var/www/openmage
command=/usr/bin/php shell/hirale_queue_worker.php --consumer=%(program_name)s_%(process_num)02d --max-runtime=3600 --max-jobs=10000
process_name=%(program_name)s_%(process_num)02d
numprocs=2
user=www-data
autostart=true
autorestart=true
stopwaitsecs=60
```

### Admin operations

Go to `System > Tools > Hirale Queue` to inspect queue health and job state.
The admin UI shows status counts and a job grid with handler, queue, attempts,
timestamps, and last error. Operators can retry a failed job, cancel a job,
purge finished jobs according to retention settings, or test the Redis
connection without using Redis CLI access.

### Producer API

Use the queue service from dependent modules:

```php
Mage::getModel('hirale_queue/queue')->enqueue(
    'hirale_queue_example/testHandler',
    ['sku' => 'ABC'],
    [
        'queue' => 'default',
        'max_attempts' => 3,
        'retry_delay' => 60,
        'delay' => 0,
        'metadata' => ['source' => 'example'],
    ],
);
```

The legacy 1.x API remains supported and delegates to the 2.x service:

```php
Mage::getModel('hirale_queue/task')->addTask(
    'hirale_queue_example/testHandler',
    ['sku' => 'ABC'],
    3,
    60,
    60,
);
```

Handlers still implement `Hirale_Queue_Model_TaskHandlerInterface`. Delivery is
at least once, so handlers must be idempotent.

### Quick start example

1. Create a new module, name it `Hirale_QueueExample`.
   `app/etc/modules/Hirale_QueueExample.xml`
   ``` xml
   <?xml version="1.0"?>
   <config>
       <modules>
           <Hirale_QueueExample>
               <active>true</active>
               <codePool>local</codePool>
               <depends>
                   <Hirale_Queue />
               </depends>
           </Hirale_QueueExample>
       </modules>
   </config>
   ```
2. Create `app/code/local/Hirale/QueueExample/etc/config.xml`.
   ```xml
   <?xml version="1.0"?>
    <config>
        <modules>
            <Hirale_QueueExample>
                <version>1.0.0</version>
            </Hirale_QueueExample>
        </modules>
        <global>
            <models>
                <hirale_queue_example>
                    <class>Hirale_QueueExample_Model</class>
                </hirale_queue_example>
            </models>
            <events>
                <controller_front_send_response_before>
                    <observers>
                        <hirale_queue_example_send_response_after>
                            <type>singleton</type>
                            <class>hirale_queue_example/observer</class>
                            <method>testExample</method>
                        </hirale_queue_example_send_response_after>
                    </observers>
                </controller_front_send_response_before>
            </events>
        </global>
    </config>
    ```
3. Create a new task handler that implements `Hirale_Queue_Model_TaskHandlerInterface`.
   `app/code/local/Hirale/QueueExample/Model/TestHandler.php`
   ```php
    <?php
        use Monolog\Level;

        class Hirale_QueueExample_Model_TestHandler implements Hirale_Queue_Model_TaskHandlerInterface
        {
            public function handle(array $data): void
            {
                Mage::log($data['id'] . ': ' . print_r($data, true), Level::Info, 'example.log');
            }
        }
    ```

4. Create Observer to get the data from a event and add it to queue.
    `app/code/local/Hirale/QueueExample/Model/Observer.php`
    ```php
    <?php
        class Hirale_QueueExample_Model_Observer
        {
            public function testExample($observer)
            {
                $currentRoute = $observer->getEvent()->getFront();
                Mage::getModel('hirale_queue/task')->addTask('hirale_queue_example/testHandler',
                ['route' => $currentRoute->getRequest()->getRequestString()]);
            }
        }
    ```
5. Enable queue processing in system config, clean cache, and check example.log. Make sure cron is running. Failed jobs can be inspected or retried from `System > Tools > Hirale Queue`.
   ``` log
   2024-06-09T14:39:01+00:00 INFO (6): 1717943907550-0: Array
    (
        [id] => 1717943907550-0
        [handler] => hirale_queue_example/testHandler
        [data] => Array
            (
                [route] => /admin/customer/index/key/5232c0583f633e8d8d8c349ebb4639db/
            )

        [retry_count] => 3
        [retry_delay] => 60
        [timeout] => 60
    )

    2024-06-09T15:04:02+00:00 INFO (6): 1717945421857-0: Array
    (
        [id] => 1717945421857-0
        [handler] => hirale_queue_example/testHandler
        [data] => Array
            (
                [route] => /admin/customer/index/key/5232c0583f633e8d8d8c349ebb4639db/
            )

        [retry_count] => 3
        [retry_delay] => 60
        [timeout] => 60
    )
    ```

## License

The Open Software License v. 3.0 (OSL-3.0). Please see [License File](LICENSE.md) for more information.
