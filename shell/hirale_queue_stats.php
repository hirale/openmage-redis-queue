<?php

declare(strict_types=1);

require_once __DIR__ . '/abstract.php';

/**
 * OpenMage stats entry point — the counterpart of Maho's
 * `./maho hirale:queue:stats`.
 */
class Hirale_Queue_Shell_Stats extends Mage_Shell_Abstract
{
    #[\Override]
    public function run(): void
    {
        /** @var Hirale_Queue_Model_Stats $stats */
        $stats = Mage::getSingleton('hirale_queue/stats');

        echo 'Totals by status:' . PHP_EOL;
        foreach ($stats->totalsByStatus() as $status => $count) {
            echo sprintf("  %-12s %d%s", $status, $count, PHP_EOL);
        }

        echo PHP_EOL . 'Per-queue depth (queued + retry_wait + running):' . PHP_EOL;
        $perQueue = $stats->perQueueDepth();
        if ($perQueue === []) {
            echo '  (no active jobs)' . PHP_EOL;
            return;
        }
        foreach ($perQueue as $row) {
            echo sprintf('  %s: %d job(s), oldest %ds%s', $row['queue'], $row['depth'], $row['oldest_seconds'], PHP_EOL);
        }
    }

    #[\Override]
    public function usageHelp(): string
    {
        return 'Usage: php shell/hirale_queue_stats.php' . PHP_EOL;
    }
}

$shell = new Hirale_Queue_Shell_Stats();
$shell->run();
