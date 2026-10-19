<?php
declare(strict_types=1);

namespace Stationwagon;

use PDO;
use PDOStatement;

/** A thin PDO wrapper that knows the table prefix. Works with MySQL 8 and MariaDB 10.5+. */
final class Db
{
    public function __construct(public readonly PDO $pdo, public readonly string $prefix)
    {
    }

    /** @param array{host:string,port?:int,name:string,user:string,pass:string,prefix?:string} $c */
    public static function connect(array $c): self
    {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int) ($c['port'] ?? 3306), $c['name']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ]);
        $pdo->exec("SET time_zone = '+00:00'"); // all DATETIME columns are UTC
        return new self($pdo, $c['prefix'] ?? 'sw_');
    }

    public static function fromConfig(): self
    {
        return self::connect(Config::get('db'));
    }

    public function table(string $name): string
    {
        return '`' . $this->prefix . $name . '`';
    }

    public function run(string $sql, array $params = []): PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public function insert(string $table, array $row): int
    {
        $cols = array_keys($row);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->table($table),
            implode(', ', array_map(fn($c) => "`$c`", $cols)),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        $this->run($sql, array_values($row));
        return (int) $this->pdo->lastInsertId();
    }
}
