<?php

declare(strict_types=1);

require_once __DIR__ . '/abstract.php';

/**
 * OpenMage liveness probe — the counterpart of Maho's
 * `./maho hirale:queue:health`. Exit 0 healthy, 1 unhealthy.
 */
class Hirale_Queue_Shell_Health extends Mage_Shell_Abstract
{
    #[\Override]
    public function run(): void
    {
        $maxAge = (int) ($this->getArg('max-age') ?: 300);

        try {
            $queues = \Hirale\Queue\MessageBusFactory::helper()->getQueueList();
            $probe  = $queues[0] ?? 'default';
            \Hirale\Queue\MessageBusFactory::reset();
            $transport = \Hirale\Queue\MessageBusFactory::getTransport($probe);
            if (method_exists($transport, 'getMessageCount')) {
                $transport->getMessageCount();
            }
        } catch (\Throwable $e) {
            echo sprintf('UNHEALTHY: backend probe failed — %s%s', $e->getMessage(), PHP_EOL);
            exit(1);
        }

        if ($maxAge > 0) {
            /** @var Hirale_Queue_Model_Stats $stats */
            $stats  = Mage::getSingleton('hirale_queue/stats');
            $oldest = $stats->oldestQueuedAgeSeconds();
            if ($oldest !== null && $oldest > $maxAge) {
                echo sprintf('UNHEALTHY: oldest active job is %ds old (threshold %ds)%s', $oldest, $maxAge, PHP_EOL);
                exit(1);
            }
        }

        echo 'OK' . PHP_EOL;
        exit(0);
    }

    #[\Override]
    public function usageHelp(): string
    {
        return <<<USAGE
Usage: php shell/hirale_queue_health.php [options]

  --max-age <seconds>  Fail if any active job is older than N seconds.
                       0 disables the age check. Default: 300
  help                 This help.

USAGE;
    }
}

$shell = new Hirale_Queue_Shell_Health();
$shell->run();
