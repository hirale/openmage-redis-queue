<?php

declare(strict_types=1);

namespace HiraleQueue\Tests\Unit;

use HiraleQueue\Tests\Support\FakeRedis;
use HiraleQueue\Tests\Support\RedisHelper;
use HiraleQueue\Tests\Support\RedisThrowingHelper;
use Hirale_Queue_Model_Task;
use Hirale_Queue_Model_Worker;
use Mage;
use PHPUnit\Framework\TestCase;

class WorkerTest extends TestCase
{
    protected function setUp(): void
    {
        Mage::resetTestState();
    }

    public function testRunExitsBeforeLoadingRedisWhenWorkerIsDisabled(): void
    {
        Mage::setStoreConfig('hirale_queue/settings/enabled', '0');
        Mage::setHelper('hirale_queue', new RedisThrowingHelper());
        $messages = [];

        $exitCode = (new Hirale_Queue_Model_Worker())->run([], function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        $this->assertSame(0, $exitCode);
        $this->assertSame(['Hirale Queue worker is disabled.'], $messages);
    }

    public function testRunOnceProcessesOneBatch(): void
    {
        Mage::setStoreConfig('hirale_queue/settings/enabled', '1');
        Mage::setHelper('hirale_queue', new RedisHelper(new FakeRedis()));
        $task = new CountingTask();
        Mage::setModel('hirale_queue/task', $task);

        $exitCode = (new Hirale_Queue_Model_Worker())->run(['once' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(1, $task->calls);
    }

    public function testRunStopsAtMaxJobs(): void
    {
        Mage::setStoreConfig('hirale_queue/settings/enabled', '1');
        Mage::setHelper('hirale_queue', new RedisHelper(new FakeRedis()));
        $task = new CountingTask();
        Mage::setModel('hirale_queue/task', $task);

        $exitCode = (new Hirale_Queue_Model_Worker())->run(['max_jobs' => 3, 'max_runtime' => 60]);

        $this->assertSame(0, $exitCode);
        $this->assertSame(3, $task->calls);
    }

    public function testRunPassesRuntimeOverridesToTask(): void
    {
        Mage::setStoreConfig('hirale_queue/settings/enabled', '1');
        Mage::setHelper('hirale_queue', new RedisHelper(new FakeRedis()));
        $task = new CountingTask();
        Mage::setModel('hirale_queue/task', $task);

        (new Hirale_Queue_Model_Worker())->run([
            'once' => true,
            'consumer' => 'worker_01',
            'count' => 25,
            'publish_limit' => 250,
        ]);

        $this->assertSame('worker_01', $task->consumer);
        $this->assertSame(25, $task->count);
        $this->assertSame(250, $task->publishLimit);
    }
}

class CountingTask extends Hirale_Queue_Model_Task
{
    public int $calls = 0;
    public ?string $consumer = null;
    public ?int $count = null;
    public ?int $publishLimit = null;

    public function processBatch(): int
    {
        $this->calls++;

        return 1;
    }

    public function setConsumer(string $consumer): self
    {
        $this->consumer = $consumer;

        return $this;
    }

    public function setCount(int $count): self
    {
        $this->count = $count;

        return $this;
    }

    public function setPublishLimit(int $publishLimit): self
    {
        $this->publishLimit = $publishLimit;

        return $this;
    }
}
