<?php

declare(strict_types=1);

namespace HiraleQueue\Tests\Support;

use Hirale_Queue_Model_TaskHandlerInterface;
use Hirale_Queue_Helper_Data;
use RuntimeException;

class CapturingTaskHandler implements Hirale_Queue_Model_TaskHandlerInterface
{
    /** @var array<string, mixed>|null */
    public ?array $lastTask = null;

    public function handle(array $task): void
    {
        $this->lastTask = $task;
    }
}

class FailingTaskHandler implements Hirale_Queue_Model_TaskHandlerInterface
{
    public function handle(array $task): void
    {
        throw new RuntimeException('handler failed');
    }
}

class RedisThrowingHelper extends Hirale_Queue_Helper_Data
{
    public function getRedis(): FakeRedis
    {
        throw new RuntimeException('Redis should not be loaded.');
    }
}

class RedisHelper extends Hirale_Queue_Helper_Data
{
    public function __construct(private readonly FakeRedis $redis)
    {
    }

    public function getRedis(): FakeRedis
    {
        return $this->redis;
    }
}

class MahoProcess
{
    /** @var int|array<int|string, int|string>|null */
    public int|array|null $entityIds = null;

    /**
     * @param int|array<int|string, int|string> $entityIds
     */
    public function reindexEntity(int|array $entityIds): void
    {
        $this->entityIds = $entityIds;
    }
}

class ResourceIndexerProcess
{
    public function __construct(private readonly ResourceIndexer $resource)
    {
    }

    public function getIndexer(): ResourceIndexer
    {
        return $this->resource;
    }
}

class ResourceIndexer
{
    public function __construct(private readonly object $resource)
    {
    }

    public function getResource(): object
    {
        return $this->resource;
    }
}

class ProductIdsResource
{
    /** @var int|array<int|string, int|string>|null */
    public int|array|null $entityIds = null;

    /**
     * @param int|array<int|string, int|string> $entityIds
     */
    public function reindexProductIds(int|array $entityIds): void
    {
        $this->entityIds = $entityIds;
    }
}

class EntitiesResource
{
    /** @var int|array<int|string, int|string>|null */
    public int|array|null $entityIds = null;

    /**
     * @param int|array<int|string, int|string> $entityIds
     */
    public function reindexEntities(int|array $entityIds): void
    {
        $this->entityIds = $entityIds;
    }
}

class ProductsResource
{
    /** @var int|array<int|string, int|string>|null */
    public int|array|null $entityIds = null;

    /**
     * @param int|array<int|string, int|string> $entityIds
     */
    public function reindexProducts(int|array $entityIds): void
    {
        $this->entityIds = $entityIds;
    }
}

class ThrowingProcess
{
    public function getIndexer(): never
    {
        throw new RuntimeException('Indexer unavailable.');
    }
}
