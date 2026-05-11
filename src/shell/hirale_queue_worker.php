<?php

declare(strict_types=1);

require_once __DIR__ . '/abstract.php';

class Hirale_Queue_Shell_Worker extends Mage_Shell_Abstract
{
    public function run(): void
    {
        $worker = Mage::getModel('hirale_queue/worker');
        if (!$worker instanceof Hirale_Queue_Model_Worker) {
            $worker = new Hirale_Queue_Model_Worker();
        }

        exit($worker->run($this->_getOptions(), function (string $message): void {
            echo $message . PHP_EOL;
        }));
    }

    public function usageHelp(): string
    {
        return <<<USAGE
Usage: php shell/hirale_queue_worker.php [options]

  --consumer <name>         Redis consumer name. Use a unique name per worker.
  --count <n>               Messages to read per batch.
  --publish-limit <n>       Due DB jobs to publish before each worker read.
  --max-runtime <seconds>   Exit after this many seconds. Default: 3600.
  --max-jobs <n>            Exit after this many processed jobs. Default: 10000.
  --idle-sleep <seconds>    Sleep after an empty batch. Default: 1.
  --once                    Process one batch and exit.
  help                      Show this help.

USAGE;
    }

    /**
     * @return array<string, mixed>
     */
    private function _getOptions(): array
    {
        $options = [
            'consumer' => $this->_getArgValue('consumer'),
            'count' => $this->_getArgValue('count'),
            'publish_limit' => $this->_getArgValue('publish-limit'),
            'max_runtime' => $this->_getArgValue('max-runtime'),
            'max_jobs' => $this->_getArgValue('max-jobs'),
            'idle_sleep' => $this->_getArgValue('idle-sleep'),
            'once' => $this->getArg('once'),
        ];

        return array_filter($options, static fn ($value): bool => $value !== null && $value !== false && $value !== '');
    }

    private function _getArgValue(string $name): ?string
    {
        $value = $this->getArg($name);
        if (is_scalar($value) && $value !== true && $value !== false) {
            return (string) $value;
        }

        $argv = $_SERVER['argv'] ?? [];
        foreach ($argv as $index => $arg) {
            if ($arg === '--' . $name && isset($argv[$index + 1])) {
                return (string) $argv[$index + 1];
            }

            $prefix = '--' . $name . '=';
            if (str_starts_with((string) $arg, $prefix)) {
                return substr((string) $arg, strlen($prefix));
            }
        }

        return null;
    }
}

$shell = new Hirale_Queue_Shell_Worker();
$shell->run();
