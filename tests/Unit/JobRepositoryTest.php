<?php

declare(strict_types=1);

namespace HiraleQueue\Tests\Unit;

use Hirale_Queue_Model_JobRepository;
use PHPUnit\Framework\TestCase;

class JobRepositoryTest extends TestCase
{
    public function testSearchDoesNotSelectLargePayloadColumnsForAdminGrid(): void
    {
        $connection = new RecordingJobConnection();

        (new Hirale_Queue_Model_JobRepository())
            ->setConnection($connection, 'hirale_queue_job')
            ->search(['status' => 'failed', 'handler' => 'test/handler', 'queue' => 'default'], 25);

        $this->assertCount(1, $connection->queries);
        $this->assertStringNotContainsString('SELECT *', $connection->queries[0]);
        $this->assertStringNotContainsString('payload_json', $connection->queries[0]);
        $this->assertStringNotContainsString('metadata_json', $connection->queries[0]);
        $this->assertStringContainsString('handler LIKE', $connection->queries[0]);
        $this->assertStringContainsString('LIMIT 25', $connection->queries[0]);
    }
}

class RecordingJobConnection
{
    /** @var list<string> */
    public array $queries = [];

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql): array
    {
        $this->queries[] = $sql;

        return [];
    }

    public function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
