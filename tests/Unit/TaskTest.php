<?php

declare(strict_types=1);

namespace HiraleQueue\Tests\Unit;

use HiraleQueue\Tests\Support\CapturingTaskHandler;
use HiraleQueue\Tests\Support\FailingTaskHandler;
use HiraleQueue\Tests\Support\FakeRedis;
use HiraleQueue\Tests\Support\RedisHelper;
use HiraleQueue\Tests\Support\RedisThrowingHelper;
use Hirale_Queue_Model_Task;
use Mage;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Predis\Response\ServerException;
use ReflectionProperty;
use stdClass;

class TaskTest extends TestCase
{
    protected function setUp(): void
    {
        Mage::resetTestState();
    }

    public function testProcessReturnsBeforeLoadingRedisWhenWorkerIsDisabled(): void
    {
        Mage::setStoreConfig('hirale_queue/settings/enabled', '0');
        Mage::setHelper('hirale_queue', new RedisThrowingHelper());

        (new Hirale_Queue_Model_Task())->process();

        $this->assertSame([], Mage::$exceptions);
    }

    public function testAddTaskWritesNormalizedPayloadToStream(): void
    {
        $redis = new FakeRedis();
        $task = $this->createTask($redis);

        $task->addTask('test/handler', ['sku' => 'ABC'], 2, 30, 90);

        $this->assertCount(1, $redis->commands);
        $command = $redis->commands[0];
        $this->assertSame(['XADD', 'test_stream', '*'], array_slice($command, 0, 3));

        $payload = $this->commandPayload($command);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $payload['id']);
        $this->assertSame('test/handler', $payload['handler']);
        $this->assertSame('{"sku":"ABC"}', $payload['data']);
        $this->assertSame('2', $payload['retry_count']);
        $this->assertSame('30', $payload['retry_delay']);
        $this->assertSame('90', $payload['timeout']);
    }

    public function testFetchTasksClaimsPendingBeforeReadingNewMessages(): void
    {
        $redis = new FakeRedis();
        $redis->queueResponse(new ServerException('BUSYGROUP Consumer Group name already exists'));
        $redis->queueResponse([]);
        $redis->queueResponse([
            [
                'test_stream',
                [
                    [
                        '1700000000000-0',
                        [
                            'handler',
                            'test/handler',
                            'data',
                            '{"sku":"ABC"}',
                            'retry_count',
                            '3',
                        ],
                    ],
                ],
            ],
        ]);

        $tasks = $this->createTask($redis)->fetchTasks();

        $this->assertSame('XGROUP', $redis->commands[0][0]);
        $this->assertSame('0', $redis->commands[1][count($redis->commands[1]) - 1]);
        $this->assertSame('>', $redis->commands[2][count($redis->commands[2]) - 1]);
        $this->assertSame([
            [
                'handler' => 'test/handler',
                'data' => ['sku' => 'ABC'],
                'retry_count' => '3',
                'stream_id' => '1700000000000-0',
            ],
        ], $tasks);
    }

    public function testDefaultConsumerNameIsRecoverableAcrossCronRuns(): void
    {
        Mage::setHelper('hirale_queue', new RedisHelper(new FakeRedis()));

        $this->assertSame('hirale_queue_worker', $this->invokePrivateMethod(new Hirale_Queue_Model_Task(), '_getConsumer'));
    }

    public function testConfiguredConsumerNameOverridesStableDefault(): void
    {
        Mage::setStoreConfig('hirale_queue/settings/consumer', 'custom_worker');
        Mage::setHelper('hirale_queue', new RedisHelper(new FakeRedis()));

        $this->assertSame('custom_worker', $this->invokePrivateMethod(new Hirale_Queue_Model_Task(), '_getConsumer'));
    }

    public function testProcessTaskAcknowledgesSuccessfulHandler(): void
    {
        $redis = new FakeRedis();
        $handler = new CapturingTaskHandler();
        Mage::setModel('test/handler', $handler);

        $task = [
            'stream_id' => '1700000000000-0',
            'handler' => 'test/handler',
            'data' => ['sku' => 'ABC'],
            'retry_count' => '3',
        ];

        $this->createTask($redis)->processTask($task);

        $this->assertSame($task, $handler->lastTask);
        $this->assertSame(['XACK', 'test_stream', 'test_group', '1700000000000-0'], $redis->commands[0]);
        $this->assertSame(['XDEL', 'test_stream', '1700000000000-0'], $redis->commands[1]);
    }

    public function testProcessTaskRequeuesFailedHandlerWithOneFewerRetry(): void
    {
        $redis = new FakeRedis();
        Mage::setModel('test/failing', new FailingTaskHandler());

        $this->createTask($redis)->processTask([
            'stream_id' => '1700000000000-0',
            'handler' => 'test/failing',
            'data' => ['sku' => 'ABC'],
            'retry_count' => '2',
            'retry_delay' => '30',
            'timeout' => '90',
        ]);

        $this->assertSame('XADD', $redis->commands[0][0]);
        $payload = $this->commandPayload($redis->commands[0]);
        $this->assertSame('test/failing', $payload['handler']);
        $this->assertSame('1', $payload['retry_count']);
        $this->assertSame('handler failed', $payload['last_error']);
        $this->assertSame(['XACK', 'test_stream', 'test_group', '1700000000000-0'], $redis->commands[1]);
        $this->assertSame(['XDEL', 'test_stream', '1700000000000-0'], $redis->commands[2]);
    }

    public function testProcessTaskDoesNotRequeueAfterRetriesAreExhausted(): void
    {
        $redis = new FakeRedis();
        Mage::setModel('test/failing', new FailingTaskHandler());

        $this->createTask($redis)->processTask([
            'stream_id' => '1700000000000-0',
            'handler' => 'test/failing',
            'data' => ['sku' => 'ABC'],
            'retry_count' => '1',
        ]);

        $this->assertSame(['XACK', 'test_stream', 'test_group', '1700000000000-0'], $redis->commands[0]);
        $this->assertSame(['XDEL', 'test_stream', '1700000000000-0'], $redis->commands[1]);
        $this->assertStringContainsString('Task exhausted retries', (string) Mage::$logs[1]['message']);
    }

    public function testProcessTaskRejectsModelThatDoesNotImplementHandlerInterface(): void
    {
        $redis = new FakeRedis();
        Mage::setModel('test/not_handler', new stdClass());

        $this->createTask($redis)->processTask([
            'stream_id' => '1700000000000-0',
            'handler' => 'test/not_handler',
            'data' => ['sku' => 'ABC'],
            'retry_count' => '2',
        ]);

        $payload = $this->commandPayload($redis->commands[0]);
        $this->assertSame('test/not_handler', $payload['handler']);
        $this->assertStringContainsString('must implement', $payload['last_error']);
    }

    private function createTask(FakeRedis $redis): Hirale_Queue_Model_Task
    {
        $task = new Hirale_Queue_Model_Task();
        Mage::setHelper('hirale_queue', new RedisHelper($redis));
        $this->setPrivateProperty($task, '_streamKey', 'test_stream');
        $this->setPrivateProperty($task, '_group', 'test_group');
        $this->setPrivateProperty($task, '_consumer', 'test_consumer');
        $this->setPrivateProperty($task, '_count', 10);

        return $task;
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

    /**
     * @param mixed $value
     */
    private function setPrivateProperty(object $object, string $property, $value): void
    {
        $reflection = new ReflectionProperty($object, $property);
        $reflection->setValue($object, $value);
    }

    /**
     * @return mixed
     */
    private function invokePrivateMethod(object $object, string $method)
    {
        $reflection = new ReflectionMethod($object, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($object);
    }
}
