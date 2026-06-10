<?php

declare(strict_types=1);

require_once __DIR__ . '/abstract.php';

/**
 * OpenMage worker entry point — the counterpart of Maho's
 * `./maho hirale:queue:consume`. Wraps the same Symfony Worker with the
 * shared WorkerListeners (job state transitions + retry redelivery).
 */
class Hirale_Queue_Shell_Worker extends Mage_Shell_Abstract
{
    #[\Override]
    public function run(): void
    {
        $queues = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($this->getArg('queues') ?: 'default')),
        )));
        if ($queues === []) {
            $queues = ['default'];
        }

        $receivers  = \Hirale\Queue\MessageBusFactory::getReceivers($queues);
        $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        \Hirale\Queue\WorkerListeners::attach(
            $dispatcher,
            \Hirale\Queue\MessageBusFactory::getRetryStrategy(),
            $receivers,
        );
        $memoryLimit = $this->getArg('memory-limit');
        \Hirale\Queue\WorkerListeners::attachStopConditions(
            $dispatcher,
            messageLimit: (int) ($this->getArg('limit') ?: 10000),
            memoryLimitBytes: $memoryLimit !== false && $memoryLimit !== null
                ? (int) $memoryLimit * 1024 * 1024
                : null,
        );

        echo sprintf('hirale_queue worker consuming from [%s]%s', implode(', ', array_keys($receivers)), PHP_EOL);

        $worker = new \Symfony\Component\Messenger\Worker(
            $receivers,
            \Hirale\Queue\MessageBusFactory::getBus(),
            $dispatcher,
        );
        \Hirale\Queue\WorkerListeners::registerSignalHandlers($worker);
        // Worker::run() supports only sleep / time_limit / queues.
        $worker->run([
            'sleep'      => (int) ($this->getArg('sleep') ?: 1) * 1_000_000,
            'queues'     => null,
            'time_limit' => (int) ($this->getArg('time-limit') ?: 3600),
        ]);
    }

    #[\Override]
    public function usageHelp(): string
    {
        return <<<USAGE
Usage: php shell/hirale_queue_worker.php [options]

  --queues <a,b>          Comma-separated queue names. Default: default
  --time-limit <seconds>  Stop after N seconds. Default: 3600
  --limit <n>             Stop after N messages. Default: 10000
  --sleep <seconds>       Sleep on an empty poll. Default: 1
  --memory-limit <MB>     Stop when the process exceeds N MB.
  help                    This help.

Run under systemd/Supervisor (or cron with flock) so the process is
restarted after the time or message limit.

USAGE;
    }
}

$shell = new Hirale_Queue_Shell_Worker();
$shell->run();
