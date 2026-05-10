<?php

declare(strict_types=1);

namespace HiraleQueue\Tests\Unit;

use HiraleQueue\Tests\Support\FakeJobRepository;
use HiraleQueue\Tests\Support\FakeRedis;
use HiraleQueue\Tests\Support\RedisHelper;
use Hirale_Queue_Model_Job;
use Hirale_Queue_Model_Queue;
use Mage;
use PHPUnit\Framework\TestCase;
use Predis\Response\ServerException;

class QueueTest extends TestCase
{
    protected function setUp(): void
    {
        Mage::resetTestState();
    }

    public function testEnqueuePersistsJobAndPublishesImmediateWork(): void
    {
        $redis = new FakeRedis();
        $redis->queueResponse('1700000000000-0');
        $repository = new FakeJobRepository();
        Mage::setHelper('hirale_queue', new RedisHelper($redis));

        $jobId = (new Hirale_Queue_Model_Queue())
            ->setRepository($repository)
            ->setRedis($redis)
            ->enqueue('test/handler', ['sku' => 'ABC'], ['max_attempts' => 5, 'retry_delay' => 45]);

        $this->assertArrayHasKey($jobId, $repository->jobs);
        $this->assertSame(Hirale_Queue_Model_Job::STATUS_PUBLISHED, $repository->jobs[$jobId]['status']);
        $this->assertSame(1, $repository->jobs[$jobId]['attempt']);
        $this->assertSame('1700000000000-0', $repository->jobs[$jobId]['stream_id']);
        $this->assertSame('XADD', $redis->commands[0][0]);

        $payload = $this->commandPayload($redis->commands[0]);
        $this->assertSame($jobId, $payload['job_id']);
        $this->assertSame('test/handler', $payload['handler']);
        $this->assertSame('{"sku":"ABC"}', $payload['data']);
        $this->assertSame('5', $payload['max_attempts']);
        $this->assertSame('45', $payload['retry_delay']);
    }

    public function testDelayedJobIsPersistedWithoutPublishingUntilDue(): void
    {
        $redis = new FakeRedis();
        $repository = new FakeJobRepository();
        Mage::setHelper('hirale_queue', new RedisHelper($redis));

        $jobId = (new Hirale_Queue_Model_Queue())
            ->setRepository($repository)
            ->setRedis($redis)
            ->enqueue('test/handler', ['sku' => 'ABC'], ['delay' => 30]);

        $this->assertSame(Hirale_Queue_Model_Job::STATUS_QUEUED, $repository->jobs[$jobId]['status']);
        $this->assertSame([], $redis->commands);
    }

    public function testRedisFailureLeavesJobQueuedForRecovery(): void
    {
        $redis = new FakeRedis();
        $redis->queueResponse(new ServerException('Redis unavailable'));
        $repository = new FakeJobRepository();
        Mage::setHelper('hirale_queue', new RedisHelper($redis));

        $jobId = (new Hirale_Queue_Model_Queue())
            ->setRepository($repository)
            ->setRedis($redis)
            ->enqueue('test/handler', ['sku' => 'ABC']);

        $this->assertSame(Hirale_Queue_Model_Job::STATUS_QUEUED, $repository->jobs[$jobId]['status']);
        $this->assertStringContainsString('Redis unavailable', (string) Mage::$logs[0]['message']);
    }

    public function testManualRetryResetsFailedJobAndPublishesIt(): void
    {
        $redis = new FakeRedis();
        $redis->queueResponse('1700000000000-0');
        $repository = new FakeJobRepository([
            [
                'job_id' => 'failed-job',
                'handler' => 'test/handler',
                'status' => Hirale_Queue_Model_Job::STATUS_FAILED,
                'payload_json' => '{"sku":"ABC"}',
                'attempt' => 3,
                'last_error' => 'failed',
            ],
        ]);
        Mage::setHelper('hirale_queue', new RedisHelper($redis));

        (new Hirale_Queue_Model_Queue())
            ->setRepository($repository)
            ->setRedis($redis)
            ->retry('failed-job');

        $this->assertSame(Hirale_Queue_Model_Job::STATUS_PUBLISHED, $repository->jobs['failed-job']['status']);
        $this->assertSame(1, $repository->jobs['failed-job']['attempt']);
        $this->assertNull($repository->jobs['failed-job']['last_error']);
    }

    /**
     * @param array<int, mixed> $command
     * @return array<string, string>
     */
    private function commandPayload(array $command): array
    {
        $payload = [];
        for ($i = 3, $count = count($command); $i < $count; $i += 2) {
            $payload[(string) $command[$i]] = (string) ($command[$i + 1] ?? '');
        }

        return $payload;
    }
}
