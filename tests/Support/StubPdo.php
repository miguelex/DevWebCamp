<?php

declare(strict_types=1);

namespace Tests\Support;

use PDO;
use PDOStatement;

final class StubStatement extends PDOStatement
{
    public array $rows = [];
    public array $values = [];
    public array $bindings = [];
    public string $sql = '';

    public function execute(?array $params = null): bool
    {
        $this->values = $params ?? [];
        return true;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        return $this->rows;
    }

    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bindings[$param] = [$value, $type];
        return true;
    }
}

final class StubPdo extends PDO
{
    public array $rows = [];
    public ?StubStatement $lastStatement = null;

    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $statement = new StubStatement();
        $statement->sql = $query;
        $statement->rows = $this->rows;
        return $this->lastStatement = $statement;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        return $this->prepare($query);
    }
}
