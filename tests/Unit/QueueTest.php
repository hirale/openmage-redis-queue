<?php

declare(strict_types=1);

namespace HiraleQueue\Tests\Unit;

use HiraleQueue\Tests\Support\FakeJobRepository;
use HiraleQueue\Tests\Support\FakeRedis;
use HiraleQueue\Tests\Support\RedisHelper;
use Hirale_Queue_Model_Job;
use Hirale_Queue_Model_Queue;
use JsonException;
use Mage;
use PHPUnit\Framework\TestCase;
use Predis\Response\ServerException;
use RuntimeException;

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

    public function testPublishDueJobsClaimsJobBeforePublishingToRedis(): void
    {
        $redis = new FakeRedis();
        $redis->queueResponse('1700000000000-0');
        $repository = new StaleDueFakeJobRepository([
            [
                'job_id' => 'race-job',
                'handler' => 'test/handler',
                'payload_json' => '{"sku":"ABC"}',
            ],
        ]);
        $repository->dueSnapshot = [$repository->jobs['race-job']];
        Mage::setHelper('hirale_queue', new RedisHelper($redis));

        $queue = (new Hirale_Queue_Model_Queue())
            ->setRepository($repository)
            ->setRedis($redis);

        $this->assertSame(1, $queue->publishDueJobs(1));
        $this->assertSame(0, $queue->publishDueJobs(1));
        $this->assertCount(1, array_filter($redis->commands, static fn (array $command): bool => $command[0] === 'XADD'));
        $this->assertSame(Hirale_Queue_Model_Job::STATUS_PUBLISHED, $repository->jobs['race-job']['status']);
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

    public function testManualRetryRejectsRunningJobWithoutPublishingIt(): void
    {
        $redis = new FakeRedis();
        $repository = new FakeJobRepository([
            [
                'job_id' => 'running-job',
                'handler' => 'test/handler',
                'status' => Hirale_Queue_Model_Job::STATUS_RUNNING,
                'payload_json' => '{"sku":"ABC"}',
                'attempt' => 1,
            ],
        ]);
        Mage::setHelper('hirale_queue', new RedisHelper($redis));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be retried');

        try {
            (new Hirale_Queue_Model_Queue())
                ->setRepository($repository)
                ->setRedis($redis)
                ->retry('running-job');
        } finally {
            $this->assertSame(Hirale_Queue_Model_Job::STATUS_RUNNING, $repository->jobs['running-job']['status']);
            $this->assertSame([], $redis->commands);
        }
    }

    public function testManualCancelRejectsRunningJobWithoutChangingStatus(): void
    {
        $redis = new FakeRedis();
        $repository = new FakeJobRepository([
            [
                'job_id' => 'running-job',
                'handler' => 'test/handler',
                'status' => Hirale_Queue_Model_Job::STATUS_RUNNING,
            ],
        ]);
        Mage::setHelper('hirale_queue', new RedisHelper($redis));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('cannot be canceled');

        try {
            (new Hirale_Queue_Model_Queue())
                ->setRepository($repository)
                ->setRedis($redis)
                ->cancel('running-job');
        } finally {
            $this->assertSame(Hirale_Queue_Model_Job::STATUS_RUNNING, $repository->jobs['running-job']['status']);
        }
    }

    public function testManualCancelAcknowledgesPublishedMessageWithoutRunningHandlerLater(): void
    {
        $redis = new FakeRedis();
        $repository = new FakeJobRepository([
            [
                'job_id' => 'published-job',
                'handler' => 'test/handler',
                'status' => Hirale_Queue_Model_Job::STATUS_PUBLISHED,
                'stream_id' => '1700000000000-0',
            ],
        ]);
        Mage::setHelper('hirale_queue', new RedisHelper($redis));

        (new Hirale_Queue_Model_Queue())
            ->setRepository($repository)
            ->setRedis($redis)
            ->cancel('published-job');

        $this->assertSame(Hirale_Queue_Model_Job::STATUS_CANCELED, $repository->jobs['published-job']['status']);
        $this->assertNull($repository->jobs['published-job']['stream_id']);
    }

    public function testEnqueueRejectsPayloadThatCannotBeJsonEncoded(): void
    {
        $redis = new FakeRedis();
        $repository = new FakeJobRepository();
        Mage::setHelper('hirale_queue', new RedisHelper($redis));
        $handle = fopen('php://memory', 'rb');
        $this->assertIsResource($handle);

        $this->expectException(JsonException::class);

        try {
            (new Hirale_Queue_Model_Queue())
                ->setRepository($repository)
                ->setRedis($redis)
                ->enqueuePayload('test/handler', ['handle' => $handle]);
        } finally {
            fclose($handle);
            $this->assertSame([], $repository->jobs);
            $this->assertSame([], $redis->commands);
        }
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

class StaleDueFakeJobRepository extends FakeJobRepository
{
    /** @var list<array<string, mixed>>|null */
    public ?array $dueSnapshot = null;

    public function listDueForPublish(int $limit): array
    {
        if ($this->dueSnapshot !== null) {
            return array_slice($this->dueSnapshot, 0, $limit);
        }

        return parent::listDueForPublish($limit);
    }
}
