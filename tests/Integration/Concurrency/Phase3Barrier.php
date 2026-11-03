<?php

namespace Tests\Integration\Concurrency;

use PDO;
use RuntimeException;

/**
 * Cross-process barrier backed by a disposable SQLite file in the Phase 3 workdir.
 *
 * Each read opens a fresh PDO connection so workers observe the latest committed
 * parent writes (Windows PDO/SQLite can otherwise retain a stale read snapshot).
 */
final class Phase3Barrier
{
    public function __construct(private readonly string $barrierPath)
    {
        if ($barrierPath === '' || str_contains($barrierPath, "\0")) {
            throw new RuntimeException('Invalid barrier path.');
        }

        $pdo = $this->connect();
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS barriers (
                name TEXT PRIMARY KEY NOT NULL,
                value TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )',
        );
        // Keep schema connection short-lived; do not retain it.
        $pdo = null;
    }

    public function path(): string
    {
        return $this->barrierPath;
    }

    public function set(string $name, string $value): void
    {
        $pdo = $this->connect();
        $stmt = $pdo->prepare(
            'INSERT INTO barriers(name, value, updated_at) VALUES(:name, :value, :updated)
             ON CONFLICT(name) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at',
        );
        $stmt->execute([
            ':name' => $name,
            ':value' => $value,
            ':updated' => gmdate('c'),
        ]);
        $stmt = null;
        $pdo = null;
    }

    public function get(string $name): ?string
    {
        $pdo = $this->connect();
        $stmt = $pdo->prepare('SELECT value FROM barriers WHERE name = :name');
        $stmt->execute([':name' => $name]);
        $value = $stmt->fetchColumn();
        $stmt = null;
        $pdo = null;

        return is_string($value) ? $value : null;
    }

    public function waitFor(string $name, string $expectedValue, int $timeoutSeconds): void
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (microtime(true) < $deadline) {
            if ($this->get($name) === $expectedValue) {
                return;
            }
            usleep(5_000);
        }

        throw new RuntimeException("Timed out waiting for barrier {$name}={$expectedValue}.");
    }

    public function countPrefix(string $prefix): int
    {
        $pdo = $this->connect();
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM barriers WHERE name LIKE :prefix');
        $stmt->execute([':prefix' => $prefix.'%']);
        $count = (int) $stmt->fetchColumn();
        $stmt = null;
        $pdo = null;

        return $count;
    }

    private function connect(): PDO
    {
        $pdo = new PDO('sqlite:'.$this->barrierPath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $pdo->exec('PRAGMA synchronous = FULL');

        return $pdo;
    }
}
