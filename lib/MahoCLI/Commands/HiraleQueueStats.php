<?php

declare(strict_types=1);

namespace MahoCLI\Commands;

use Hirale_Queue_Model_Stats;
use Mage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Per-queue depth and per-status totals snapshot. Used by ops dashboards and
 * the Prometheus textfile collector (--format=json).
 */
#[AsCommand(name: 'hirale:queue:stats', description: 'Snapshot of Hirale Queue depth and per-status totals.')]
final class HiraleQueueStats extends Command
{
    protected function configure(): void
    {
        $this->addOption(
            'format',
            null,
            InputOption::VALUE_REQUIRED,
            'Output format: "text" or "json".',
            'text',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Mage::app('admin');

        /** @var Hirale_Queue_Model_Stats $stats */
        $stats = Mage::getSingleton('hirale_queue/stats');
        $snapshot = [
            'totals_by_status' => $stats->totalsByStatus(),
            'per_queue'        => $stats->perQueueDepth(),
            'timestamp'        => time(),
        ];

        $format = (string) $input->getOption('format');
        if ($format === 'json') {
            $output->writeln((string) json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $output->writeln('<info>Totals by status:</info>');
        foreach ($snapshot['totals_by_status'] as $status => $count) {
            $output->writeln(sprintf('  %-12s %d', $status, $count));
        }
        $output->writeln('');
        $output->writeln('<info>Per-queue depth (queued + retry_wait + running):</info>');
        if ($snapshot['per_queue'] === []) {
            $output->writeln('  (no active jobs)');
        }
        foreach ($snapshot['per_queue'] as $row) {
            $output->writeln(sprintf(
                '  %-20s depth=%d  oldest=%ds',
                $row['queue'],
                $row['depth'],
                $row['oldest_seconds'],
            ));
        }
        return Command::SUCCESS;
    }
}
