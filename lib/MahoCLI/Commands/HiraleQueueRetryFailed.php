<?php

declare(strict_types=1);

namespace MahoCLI\Commands;

use Hirale\Queue\Bus;
use Hirale\Queue\MessageReconstructor;
use Hirale\Queue\Stamp\JobIdStamp;
use Mage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Bulk recovery after an outage: re-dispatches `failed` jobs from
 * hirale_queue_job. Each retried job becomes a fresh dispatch (new job_id,
 * attempt counter reset); the old row is marked superseded so a second run
 * never double-dispatches.
 *
 *   ./maho hirale:queue:retry-failed
 *   ./maho hirale:queue:retry-failed --queue=default --since="-2 hours" --limit=500
 */
#[AsCommand(name: 'hirale:queue:retry-failed', description: 'Re-dispatch failed Hirale Queue jobs from the DB job table.')]
final class HiraleQueueRetryFailed extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('queue', null, InputOption::VALUE_REQUIRED, 'Only retry jobs from this queue.')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Only retry jobs that failed at/after this time (strtotime syntax, e.g. "-2 hours").')
            ->addOption('limit', null, InputOption::VALUE_REQUIRED, 'Maximum number of jobs to retry.', 100);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Mage::app('admin');

        $sinceUtc = null;
        $since    = $input->getOption('since');
        if ($since !== null && $since !== '') {
            $ts = strtotime((string) $since);
            if ($ts === false) {
                $output->writeln(sprintf('<error>Cannot parse --since value "%s".</error>', $since));
                return Command::INVALID;
            }
            $sinceUtc = gmdate('Y-m-d H:i:s', $ts);
        }

        /** @var \Hirale_Queue_Model_JobRepository $repo */
        $repo = Mage::getSingleton('hirale_queue/jobRepository');
        $rows = $repo->findFailed(
            $input->getOption('queue'),
            $sinceUtc,
            (int) $input->getOption('limit'),
        );

        if ($rows === []) {
            $output->writeln('<comment>No failed jobs matched.</comment>');
            return Command::SUCCESS;
        }

        $retried = 0;
        $errors  = 0;
        foreach ($rows as $row) {
            $jobId = (string) $row['job_id'];
            try {
                $payload  = json_decode((string) $row['payload_json'], true) ?: [];
                $message  = MessageReconstructor::reconstruct((string) $row['message_class'], $payload);
                $envelope = Bus::dispatch($message);
                $newJobId = $envelope->last(JobIdStamp::class)->jobId ?? '';
                $repo->markSuperseded($jobId, $newJobId);
                $output->writeln(sprintf('<info>retried</info> %s -> %s  %s', $jobId, $newJobId, $row['message_class']));
                $retried++;
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>error</error>   %s  %s', $jobId, $e->getMessage()));
                $errors++;
            }
        }

        $output->writeln(sprintf('Done: %d retried, %d errors.', $retried, $errors));
        return $errors > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
