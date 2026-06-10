<?php

declare(strict_types=1);

namespace MahoCLI\Commands;

use Hirale\Queue\MessageBusFactory;
use Hirale_Queue_Model_Stats;
use Mage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Liveness probe for k8s / load balancers. Exits 0 if:
 *  - The configured backend is reachable (we can query the default transport)
 *  - No active job is older than --max-age seconds (default 300)
 *
 * Exits 1 with a message otherwise.
 */
#[AsCommand(name: 'hirale:queue:health', description: 'Liveness check: backend reachable + no stale active jobs.')]
final class HiraleQueueHealth extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'max-age',
            null,
            InputOption::VALUE_REQUIRED,
            'Fail if any active job is older than N seconds. 0 disables the age check.',
            300,
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Mage::app('admin');

        $maxAge = (int) $input->getOption('max-age');

        // 1) Backend reachability — query the default queue's transport.
        try {
            $queues = MessageBusFactory::helper()->getQueueList();
            $probe  = $queues[0] ?? 'default';
            MessageBusFactory::reset();
            $transport = MessageBusFactory::getTransport($probe);
            if (method_exists($transport, 'getMessageCount')) {
                $transport->getMessageCount();
            }
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>UNHEALTHY: backend probe failed — %s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        // 2) Oldest active-job age check.
        if ($maxAge > 0) {
            /** @var Hirale_Queue_Model_Stats $stats */
            $stats = Mage::getSingleton('hirale_queue/stats');
            $oldest = $stats->oldestQueuedAgeSeconds();
            if ($oldest !== null && $oldest > $maxAge) {
                $output->writeln(sprintf(
                    '<error>UNHEALTHY: oldest active job is %ds old (threshold %ds)</error>',
                    $oldest,
                    $maxAge,
                ));
                return Command::FAILURE;
            }
        }

        $output->writeln('<info>OK</info>');
        return Command::SUCCESS;
    }
}
