<?php

declare(strict_types=1);

namespace MahoCLI\Commands;

use Hirale\Queue\Bus;
use Hirale\Queue\MessageBusFactory;
use Hirale\Queue\Stamp\JobIdStamp;
use Hirale\Queue\WorkerListeners;
use Mage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Event\WorkerRunningEvent;
use Symfony\Component\Messenger\Worker;

/**
 * One-command end-to-end self-test: dispatches a built-in PingMessage, drains
 * the queue inline with the same worker wiring `hirale:queue:consume` uses,
 * and asserts the job row reached `succeeded`. Verifies config, transport
 * round-trip, handler resolution, and DB observability in one go — handy on a
 * fresh install before any consumer module exists.
 */
#[AsCommand(name: 'hirale:queue:test', description: 'Self-test: dispatch a ping message, consume it, and verify the job succeeded.')]
final class HiraleQueueTest extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Queue to test.', 'default')
            ->addOption('timeout', null, InputOption::VALUE_REQUIRED, 'Give up after N seconds.', 30);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Mage::app('admin');

        $queue   = (string) $input->getOption('queue');
        $timeout = max(1, (int) $input->getOption('timeout'));
        $token   = bin2hex(random_bytes(8));

        $envelope = Bus::dispatchOnQueue(new \Hirale_Queue_Message_PingMessage($token), $queue);
        $jobId    = $envelope->last(JobIdStamp::class)->jobId ?? '';
        if ($jobId === '') {
            $output->writeln('<error>Dispatch did not produce a job id.</error>');
            return Command::FAILURE;
        }
        $output->writeln(sprintf('dispatched ping token=%s job=%s queue=%s', $token, $jobId, $queue));

        // Drain inline: same listener wiring as hirale:queue:consume, stopping
        // as soon as the queue is idle (or on the timeout guard).
        $receivers  = MessageBusFactory::getReceivers([$queue]);
        $dispatcher = new EventDispatcher();
        WorkerListeners::attach($dispatcher, MessageBusFactory::getRetryStrategy(), $receivers);
        $dispatcher->addListener(WorkerRunningEvent::class, function (WorkerRunningEvent $event): void {
            if ($event->isWorkerIdle()) {
                $event->getWorker()->stop();
            }
        });

        $worker = new Worker($receivers, MessageBusFactory::getBus(), $dispatcher);
        $worker->run([
            'sleep'      => 200_000,
            'queues'     => null,
            'signals'    => true,
            'time-limit' => $timeout,
        ]);

        /** @var \Hirale_Queue_Model_JobRepository $repo */
        $repo = Mage::getSingleton('hirale_queue/jobRepository');
        $row  = $repo->findByJobId($jobId);
        $status = (string) ($row['status'] ?? 'missing');

        if ($status === \Hirale_Queue_Model_Job::STATUS_SUCCEEDED) {
            $output->writeln(sprintf('<info>OK</info> job %s succeeded — dispatch, transport, handler, and DB tracking all work.', $jobId));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '<error>FAILED</error> job %s ended in status "%s"%s',
            $jobId,
            $status,
            !empty($row['last_error']) ? ': ' . $row['last_error'] : '',
        ));
        return Command::FAILURE;
    }
}
