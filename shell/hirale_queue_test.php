<?php

declare(strict_types=1);

require_once __DIR__ . '/abstract.php';

/**
 * OpenMage self-test entry point — the counterpart of Maho's
 * `./maho hirale:queue:test`. Dispatches a built-in PingMessage, drains the
 * queue inline with the shared worker wiring, and asserts the job row
 * reached `succeeded`.
 */
class Hirale_Queue_Shell_Test extends Mage_Shell_Abstract
{
    #[\Override]
    public function run(): void
    {
        $queue   = (string) ($this->getArg('queue') ?: 'default');
        $timeout = max(1, (int) ($this->getArg('timeout') ?: 30));
        $token   = bin2hex(random_bytes(8));

        $envelope = \Hirale\Queue\Bus::dispatchOnQueue(new Hirale_Queue_Message_PingMessage($token), $queue);
        $jobId    = $envelope->last(\Hirale\Queue\Stamp\JobIdStamp::class)?->jobId ?? '';
        if ($jobId === '') {
            echo 'Dispatch did not produce a job id.' . PHP_EOL;
            exit(1);
        }
        echo sprintf('dispatched ping token=%s job=%s queue=%s%s', $token, $jobId, $queue, PHP_EOL);

        $receivers  = \Hirale\Queue\MessageBusFactory::getReceivers([$queue]);
        $dispatcher = new \Symfony\Component\EventDispatcher\EventDispatcher();
        \Hirale\Queue\WorkerListeners::attach(
            $dispatcher,
            \Hirale\Queue\MessageBusFactory::getRetryStrategy(),
            $receivers,
        );
        $dispatcher->addListener(
            \Symfony\Component\Messenger\Event\WorkerRunningEvent::class,
            function (\Symfony\Component\Messenger\Event\WorkerRunningEvent $event): void {
                if ($event->isWorkerIdle()) {
                    $event->getWorker()->stop();
                }
            },
        );

        $worker = new \Symfony\Component\Messenger\Worker(
            $receivers,
            \Hirale\Queue\MessageBusFactory::getBus(),
            $dispatcher,
        );
        \Hirale\Queue\WorkerListeners::registerSignalHandlers($worker);
        $worker->run([
            'sleep'      => 200_000,
            'queues'     => null,
            'time_limit' => $timeout,
        ]);

        /** @var Hirale_Queue_Model_JobRepository $repo */
        $repo   = Mage::getSingleton('hirale_queue/jobRepository');
        $row    = $repo->findByJobId($jobId);
        $status = (string) ($row['status'] ?? 'missing');

        if ($status === Hirale_Queue_Model_Job::STATUS_SUCCEEDED) {
            echo sprintf(
                'OK job %s succeeded — dispatch, transport, handler, and DB tracking all work.%s',
                $jobId,
                PHP_EOL,
            );
            exit(0);
        }

        echo sprintf(
            'FAILED job %s ended in status "%s"%s%s',
            $jobId,
            $status,
            !empty($row['last_error']) ? ': ' . $row['last_error'] : '',
            PHP_EOL,
        );
        exit(1);
    }

    #[\Override]
    public function usageHelp(): string
    {
        return <<<USAGE
Usage: php shell/hirale_queue_test.php [options]

  --queue <name>       Queue to test. Default: default
  --timeout <seconds>  Give up after N seconds. Default: 30
  help                 This help.

USAGE;
    }
}

$shell = new Hirale_Queue_Shell_Test();
$shell->run();
