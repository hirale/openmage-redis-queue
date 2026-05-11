<?php

declare(strict_types=1);

class Hirale_Queue_Model_Worker
{
    private bool $_shouldStop = false;

    /**
     * Run the queue worker loop.
     *
     * @param array<string, mixed> $options
     */
    public function run(array $options = [], ?callable $output = null): int
    {
        if (!$this->_isEnabled()) {
            $this->_write($output, 'Hirale Queue worker is disabled.');
            return 0;
        }

        $this->_shouldStop = false;
        $this->_registerSignalHandlers();

        $maxRuntime = $this->_positiveInt($options['max_runtime'] ?? 3600, 3600);
        $maxJobs = $this->_positiveInt($options['max_jobs'] ?? 10000, 10000);
        $idleSleep = $this->_positiveInt($options['idle_sleep'] ?? 1, 1);
        $once = !empty($options['once']);
        $startedAt = time();
        $processed = 0;

        $task = $this->_getTaskModel();
        $task->setConsumer((string) ($options['consumer'] ?? $this->_defaultConsumer()));
        if (!empty($options['count'])) {
            $task->setCount($this->_positiveInt($options['count'], 10));
        }
        if (!empty($options['publish_limit'])) {
            $task->setPublishLimit($this->_positiveInt($options['publish_limit'], 100));
        }

        try {
            while (!$this->_shouldStop) {
                $count = $task->processBatch();
                $processed += $count;
                $this->_dispatchSignals();

                if ($once || $processed >= $maxJobs || time() - $startedAt >= $maxRuntime) {
                    break;
                }

                if ($count === 0) {
                    sleep($idleSleep);
                }
            }
        } catch (Throwable $e) {
            Mage::logException($e);
            $this->_write($output, $e->getMessage());
            return 1;
        }

        $this->_write($output, sprintf('Hirale Queue worker stopped after processing %d job(s).', $processed));

        return 0;
    }

    /**
     * @param mixed $value
     */
    private function _positiveInt($value, int $default): int
    {
        $value = (int) $value;

        return $value > 0 ? $value : $default;
    }

    private function _isEnabled(): bool
    {
        $helper = Mage::helper('hirale_queue');

        return $helper instanceof Hirale_Queue_Helper_Data && $helper->getConfigFlag('enabled');
    }

    private function _getTaskModel(): Hirale_Queue_Model_Task
    {
        $task = Mage::getModel('hirale_queue/task');
        if (!$task instanceof Hirale_Queue_Model_Task) {
            $task = new Hirale_Queue_Model_Task();
        }

        return $task;
    }

    private function _defaultConsumer(): string
    {
        $hostname = gethostname();
        $hostname = is_string($hostname) && $hostname !== '' ? $hostname : 'localhost';

        return sprintf('hirale_queue_worker_%s_%d', preg_replace('/[^A-Za-z0-9_:-]/', '_', $hostname), getmypid() ?: 0);
    }

    private function _registerSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_signal(SIGTERM, function (): void {
            $this->_shouldStop = true;
        });
        pcntl_signal(SIGINT, function (): void {
            $this->_shouldStop = true;
        });
    }

    private function _dispatchSignals(): void
    {
        if (function_exists('pcntl_signal_dispatch')) {
            pcntl_signal_dispatch();
        }
    }

    private function _write(?callable $output, string $message): void
    {
        if ($output !== null) {
            $output($message);
        }
    }
}
