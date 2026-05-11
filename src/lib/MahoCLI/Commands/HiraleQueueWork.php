<?php

declare(strict_types=1);

namespace MahoCLI\Commands;

use Hirale_Queue_Model_Worker;
use Mage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'hirale:queue:work',
    description: 'Run the Hirale Redis Queue worker'
)]
class HiraleQueueWork extends BaseMahoCommand
{
    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption('consumer', null, InputOption::VALUE_REQUIRED, 'Redis consumer name. Use a unique name per worker.')
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'Messages to read per batch.')
            ->addOption('publish-limit', null, InputOption::VALUE_REQUIRED, 'Due DB jobs to publish before each worker read.')
            ->addOption('max-runtime', null, InputOption::VALUE_REQUIRED, 'Exit after this many seconds.', 3600)
            ->addOption('max-jobs', null, InputOption::VALUE_REQUIRED, 'Exit after this many processed jobs.', 10000)
            ->addOption('idle-sleep', null, InputOption::VALUE_REQUIRED, 'Sleep after an empty batch.', 1)
            ->addOption('once', null, InputOption::VALUE_NONE, 'Process one batch and exit.');
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->initMaho();

        $worker = Mage::getModel('hirale_queue/worker');
        if (!$worker instanceof Hirale_Queue_Model_Worker) {
            $worker = new Hirale_Queue_Model_Worker();
        }

        return $worker->run([
            'consumer' => $input->getOption('consumer'),
            'count' => $input->getOption('count'),
            'publish_limit' => $input->getOption('publish-limit'),
            'max_runtime' => $input->getOption('max-runtime'),
            'max_jobs' => $input->getOption('max-jobs'),
            'idle_sleep' => $input->getOption('idle-sleep'),
            'once' => (bool) $input->getOption('once'),
        ], function (string $message) use ($output): void {
            $output->writeln($message);
        }) === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
