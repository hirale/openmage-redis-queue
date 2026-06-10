<?php

declare(strict_types=1);

require_once __DIR__ . '/abstract.php';

/**
 * OpenMage bulk retry — the counterpart of Maho's
 * `./maho hirale:queue:retry-failed`. Re-dispatches failed jobs from the DB;
 * old rows are marked superseded so reruns never double-dispatch.
 */
class Hirale_Queue_Shell_RetryFailed extends Mage_Shell_Abstract
{
    #[\Override]
    public function run(): void
    {
        $sinceUtc = null;
        $since    = $this->getArg('since');
        if (is_string($since) && $since !== '') {
            $ts = strtotime($since);
            if ($ts === false) {
                echo sprintf('Cannot parse --since value "%s".%s', $since, PHP_EOL);
                exit(2);
            }
            $sinceUtc = gmdate('Y-m-d H:i:s', $ts);
        }

        /** @var Hirale_Queue_Model_JobRepository $repo */
        $repo = Mage::getSingleton('hirale_queue/jobRepository');
        $rows = $repo->findFailed(
            is_string($this->getArg('queue')) ? $this->getArg('queue') : null,
            $sinceUtc,
            (int) ($this->getArg('limit') ?: 100),
        );

        if ($rows === []) {
            echo 'No failed jobs matched.' . PHP_EOL;
            exit(0);
        }

        $retried = 0;
        $errors  = 0;
        foreach ($rows as $row) {
            $jobId = (string) $row['job_id'];
            try {
                $payload  = json_decode((string) $row['payload_json'], true) ?: [];
                $message  = \Hirale\Queue\MessageReconstructor::reconstruct((string) $row['message_class'], $payload);
                $envelope = \Hirale\Queue\Bus::dispatch($message);
                $newJobId = $envelope->last(\Hirale\Queue\Stamp\JobIdStamp::class)?->jobId ?? '';
                $repo->markSuperseded($jobId, $newJobId);
                echo sprintf('retried %s -> %s  %s%s', $jobId, $newJobId, $row['message_class'], PHP_EOL);
                $retried++;
            } catch (\Throwable $e) {
                echo sprintf('error   %s  %s%s', $jobId, $e->getMessage(), PHP_EOL);
                $errors++;
            }
        }

        echo sprintf('Done: %d retried, %d errors.%s', $retried, $errors, PHP_EOL);
        exit($errors > 0 ? 1 : 0);
    }

    #[\Override]
    public function usageHelp(): string
    {
        return <<<USAGE
Usage: php shell/hirale_queue_retry_failed.php [options]

  --queue <name>     Only retry jobs from this queue.
  --since <expr>     Only retry jobs failed at/after this time (strtotime syntax).
  --limit <n>        Maximum number of jobs to retry. Default: 100
  help               This help.

USAGE;
    }
}

$shell = new Hirale_Queue_Shell_RetryFailed();
$shell->run();
