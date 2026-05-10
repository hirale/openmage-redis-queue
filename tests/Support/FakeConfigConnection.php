<?php

declare(strict_types=1);

namespace HiraleQueue\Tests\Support;

class FakeConfigConnection
{
    /** @var list<array{config_id: int, scope: string, scope_id: int, path: string, value: string}> */
    public array $rows = [];

    private int $nextId = 1;

    /**
     * @param list<array{scope: string, scope_id: int, path: string, value: string}> $rows
     */
    public function __construct(array $rows)
    {
        foreach ($rows as $row) {
            $this->insert('core_config_data', $row);
        }
    }

    public function quote(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * @return list<array{scope: string, scope_id: int, value: string}>
     */
    public function fetchAll(string $sql): array
    {
        preg_match("/path = '([^']+)'/", $sql, $matches);
        $path = $matches[1] ?? '';

        $rows = [];
        foreach ($this->rows as $row) {
            if ($row['path'] === $path) {
                $rows[] = [
                    'scope' => $row['scope'],
                    'scope_id' => $row['scope_id'],
                    'value' => $row['value'],
                ];
            }
        }

        return $rows;
    }

    public function fetchOne(string $sql): int|false
    {
        preg_match("/scope = '([^']+)'/", $sql, $scopeMatches);
        preg_match('/scope_id = (\\d+)/', $sql, $scopeIdMatches);
        preg_match("/path = '([^']+)'/", $sql, $pathMatches);

        $scope = $scopeMatches[1] ?? '';
        $scopeId = (int) ($scopeIdMatches[1] ?? -1);
        $path = $pathMatches[1] ?? '';

        foreach ($this->rows as $row) {
            if ($row['scope'] === $scope && $row['scope_id'] === $scopeId && $row['path'] === $path) {
                return $row['config_id'];
            }
        }

        return false;
    }

    /**
     * @param array{scope: string, scope_id: int, path: string, value: string} $data
     */
    public function insert(string $table, array $data): void
    {
        $this->rows[] = [
            'config_id' => $this->nextId++,
            'scope' => $data['scope'],
            'scope_id' => (int) $data['scope_id'],
            'path' => $data['path'],
            'value' => $data['value'],
        ];
    }
}
