<?php

declare(strict_types=1);

namespace MahoCLI\Commands;

use Hirale\Queue\MessageBusFactory;
use Hirale\Queue\WorkerListeners;
use Mage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Worker;

/**
 * The consumer entry point: `./maho hirale:queue:consume [<queue>...]`.
 *
 * Bootstraps Maho, builds the bus + receivers from admin config, attaches the
 * shared WorkerListeners (job state transitions + retry redelivery), and runs
 * Symfony's Worker. The retry policy lives on each envelope (RetryPolicyStamp
 * captured at dispatch); the RetryStrategy reads from there per-message, so a
 * worker serving messages from many stores never has to look up live config
 * per message.
 *
 * Clean shutdown on SIGTERM/SIGINT is handled by Worker's internal signal
 * support (`signals: true` run option).
 */
#[AsCommand(name: 'hirale:queue:consume', description: 'Consume Hirale Queue messages from the configured backend.')]
final class HiraleQueueConsume extends Command
{
    protected function configure(): void
    {
        $this
            ->addArgument(
                'queues',
                InputArgument::IS_ARRAY | InputArgument::OPTIONAL,
                'One or more queue names to consume. Defaults to ["default"].',
                ['default'],
            )
            ->addOption('time-limit', null, InputOption::VALUE_REQUIRED, 'Stop after N seconds.', 3600)
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Stop after N messages.', 10000)
            ->addOption('sleep', null, InputOption::VALUE_REQUIRED, 'Seconds to sleep on an empty poll.', 1)
            ->addOption('memory-limit', null, InputOption::VALUE_REQUIRED, 'Stop when the process exceeds N MB.')
            ->addOption('consumer', null, InputOption::VALUE_REQUIRED, 'Override transport consumer name (Redis only).');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Mage::app('admin');

        /** @var list<string> $queues */
        $queues = $input->getArgument('queues');
        if ($queues === []) {
            $queues = ['default'];
        }

        $receivers  = MessageBusFactory::getReceivers($queues);
        $bus        = MessageBusFactory::getBus();
        $dispatcher = new EventDispatcher();

        WorkerListeners::attach($dispatcher, MessageBusFactory::getRetryStrategy(), $receivers);

        $output->writeln(sprintf(
            '<info>hirale:queue:consume</info> consuming from [%s]',
            implode(', ', array_keys($receivers)),
        ));

        $worker = new Worker($receivers, $bus, $dispatcher);
        $worker->run([
            'sleep'        => (int) $input->getOption('sleep') * 1_000_000,
            'queues'       => null,
            'signals'      => true,
            'memory-limit' => $input->getOption('memory-limit') !== null
                ? (int) $input->getOption('memory-limit') * 1024 * 1024
                : null,
            'time-limit'   => (int) $input->getOption('time-limit'),
            'limit'        => (int) $input->getOption('limit'),
        ]);

        return Command::SUCCESS;
    }
}
