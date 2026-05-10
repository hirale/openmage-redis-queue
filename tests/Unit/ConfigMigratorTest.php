<?php

declare(strict_types=1);

namespace HiraleQueue\Tests\Unit;

use HiraleQueue\Tests\Support\FakeConfigConnection;
use Hirale_Queue_Model_ConfigMigrator;
use PHPUnit\Framework\TestCase;

class ConfigMigratorTest extends TestCase
{
    public function testMigratesLegacyConfigValuesToNewQueueSection(): void
    {
        $connection = new FakeConfigConnection([
            [
                'scope' => 'default',
                'scope_id' => 0,
                'path' => 'system/hirale_queue/enabled',
                'value' => '1',
            ],
            [
                'scope' => 'websites',
                'scope_id' => 2,
                'path' => 'system/hirale_queue/dsn',
                'value' => 'redis://redis.example.test:6379/5',
            ],
        ]);

        (new Hirale_Queue_Model_ConfigMigrator())->migrate($connection, 'core_config_data');

        $this->assertTrue($this->hasRow($connection, 'default', 0, 'hirale_queue/settings/enabled', '1'));
        $this->assertTrue($this->hasRow($connection, 'websites', 2, 'hirale_queue/settings/dsn', 'redis://redis.example.test:6379/5'));
    }

    public function testDoesNotOverwriteExistingNewConfigValues(): void
    {
        $connection = new FakeConfigConnection([
            [
                'scope' => 'default',
                'scope_id' => 0,
                'path' => 'system/hirale_queue/enabled',
                'value' => '1',
            ],
            [
                'scope' => 'default',
                'scope_id' => 0,
                'path' => 'hirale_queue/settings/enabled',
                'value' => '0',
            ],
        ]);

        (new Hirale_Queue_Model_ConfigMigrator())->migrate($connection, 'core_config_data');

        $matches = array_filter(
            $connection->rows,
            static fn (array $row): bool => $row['path'] === 'hirale_queue/settings/enabled',
        );

        $this->assertCount(1, $matches);
        $this->assertTrue($this->hasRow($connection, 'default', 0, 'hirale_queue/settings/enabled', '0'));
    }

    private function hasRow(
        FakeConfigConnection $connection,
        string $scope,
        int $scopeId,
        string $path,
        string $value,
    ): bool {
        foreach ($connection->rows as $row) {
            if (
                $row['scope'] === $scope
                && $row['scope_id'] === $scopeId
                && $row['path'] === $path
                && $row['value'] === $value
            ) {
                return true;
            }
        }

        return false;
    }
}
