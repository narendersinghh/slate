<?php
declare(strict_types=1);

namespace Slate;

use PDO;
use PDOStatement;

/**
 * Thin PDO wrapper. Supports MySQL/MariaDB and SQLite.
 * Table names are written as {{name}} and receive the configured prefix.
 */
final class DB
{
    private static ?PDO $pdo = null;
    public static string $prefix = 'sl_';
    public static string $driver = 'mysql';
    public static int $count = 0;

    public static function connect(array $cfg): void
    {
        self::$driver = $cfg['driver'] ?? 'mysql';
        self::$prefix = $cfg['prefix'] ?? 'sl_';
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ];
        if (self::$driver === 'sqlite') {
            $path = $cfg['path'] ?? SLATE_CONTENT . '/database.sqlite';
            self::$pdo = new PDO('sqlite:' . $path, null, null, $options);
            self::$pdo->exec('PRAGMA journal_mode = WAL; PRAGMA foreign_keys = ON; PRAGMA synchronous = NORMAL;');
        } else {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $cfg['host'] ?? 'localhost',
                (int)($cfg['port'] ?? 3306),
                $cfg['name'] ?? ''
            );
            $options[PDO::ATTR_PERSISTENT] = !empty($cfg['persistent']);
            self::$pdo = new PDO($dsn, $cfg['user'] ?? '', $cfg['pass'] ?? '', $options);
            self::$pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, sql_mode = 'STRICT_ALL_TABLES'");
        }
    }

    public static function connected(): bool
    {
        return self::$pdo !== null;
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo) {
            throw new \RuntimeException('Database is not connected.');
        }
        return self::$pdo;
    }

    public static function t(string $table): string
    {
        return self::$prefix . $table;
    }

    private static function prepareSql(string $sql): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', static fn($m) => self::$prefix . $m[1], $sql);
    }

    public static function query(string $sql, array $params = []): PDOStatement
    {
        self::$count++;
        $stmt = self::pdo()->prepare(self::prepareSql($sql));
        foreach ($params as $key => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue(is_int($key) ? $key + 1 : ':' . ltrim((string)$key, ':'), $value, $type);
        }
        $stmt->execute();
        return $stmt;
    }

    public static function exec(string $sql): void
    {
        self::$count++;
        self::pdo()->exec(self::prepareSql($sql));
    }

    public static function row(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function val(string $sql, array $params = []): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function col(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        self::assertIdentifiers($cols);
        $sql = sprintf(
            'INSERT INTO {{%s}} (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_map(static fn($c) => ':' . $c, $cols))
        );
        self::query($sql, $data);
        return (int)self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, array $where): int
    {
        self::assertIdentifiers(array_merge(array_keys($data), array_keys($where)));
        $set = implode(', ', array_map(static fn($c) => "$c = :s_$c", array_keys($data)));
        $cond = implode(' AND ', array_map(static fn($c) => "$c = :w_$c", array_keys($where)));
        $params = [];
        foreach ($data as $k => $v) {
            $params["s_$k"] = $v;
        }
        foreach ($where as $k => $v) {
            $params["w_$k"] = $v;
        }
        return self::query("UPDATE {{{$table}}} SET $set WHERE $cond", $params)->rowCount();
    }

    public static function delete(string $table, array $where): int
    {
        self::assertIdentifiers(array_keys($where));
        $cond = implode(' AND ', array_map(static fn($c) => "$c = :$c", array_keys($where)));
        return self::query("DELETE FROM {{{$table}}} WHERE $cond", $where)->rowCount();
    }

    /** Insert or update by unique key. */
    public static function upsert(string $table, array $data, string $key): void
    {
        $cols = array_keys($data);
        self::assertIdentifiers($cols);
        $colList = implode(', ', $cols);
        $vals = implode(', ', array_map(static fn($c) => ':' . $c, $cols));
        $updates = array_filter($cols, static fn($c) => $c !== $key);
        if (self::$driver === 'sqlite') {
            $set = implode(', ', array_map(static fn($c) => "$c = excluded.$c", $updates));
            $sql = "INSERT INTO {{{$table}}} ($colList) VALUES ($vals) ON CONFLICT($key) DO UPDATE SET $set";
        } else {
            $set = implode(', ', array_map(static fn($c) => "$c = VALUES($c)", $updates));
            $sql = "INSERT INTO {{{$table}}} ($colList) VALUES ($vals) ON DUPLICATE KEY UPDATE $set";
        }
        self::query($sql, $data);
    }

    /** Build an IN() placeholder list and params for integer ids. */
    public static function in(array $ids, string $prefix = 'id'): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) {
            return ['NULL', []];
        }
        $ph = [];
        $params = [];
        foreach ($ids as $i => $id) {
            $ph[] = ":{$prefix}{$i}";
            $params["{$prefix}{$i}"] = $id;
        }
        return [implode(',', $ph), $params];
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function assertIdentifiers(array $names): void
    {
        foreach ($names as $n) {
            if (!preg_match('/^[a-z_][a-z0-9_]*$/i', (string)$n)) {
                throw new \InvalidArgumentException("Invalid identifier: $n");
            }
        }
    }
}
