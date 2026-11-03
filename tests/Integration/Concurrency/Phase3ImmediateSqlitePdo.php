<?php

namespace Tests\Integration\Concurrency;

use PDO;
use PDOException;
use RuntimeException;

/**
 * Test-only PDO that starts SQLite transactions with BEGIN IMMEDIATE while keeping
 * PDO::inTransaction() / commit() / rollBack() in sync for Laravel's ManagesTransactions.
 *
 * Required on PHP < 8.4: exec('BEGIN IMMEDIATE') alone leaves PDO unaware of the
 * transaction, so later PDO::commit() raises "There is no active transaction".
 */
final class Phase3ImmediateSqlitePdo extends PDO
{
    private bool $immediateTransactionOpen = false;

    public static function forDatabase(string $databasePath, array $config = []): self
    {
        if ($databasePath === '' || $databasePath === ':memory:') {
            throw new RuntimeException('Phase3ImmediateSqlitePdo requires a disposable file database path.');
        }

        $pdo = new self('sqlite:'.$databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 5,
        ]);

        $busy = (int) ($config['busy_timeout'] ?? 5000);
        $pdo->exec('PRAGMA busy_timeout = '.$busy);

        $foreign = $config['foreign_key_constraints'] ?? true;
        if ($foreign === true || $foreign === 1 || $foreign === '1' || $foreign === 'true') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        $journal = strtolower((string) ($config['journal_mode'] ?? 'wal'));
        if ($journal !== '') {
            $pdo->exec('PRAGMA journal_mode = '.$journal);
        }

        return $pdo;
    }

    public function beginTransaction(): bool
    {
        if ($this->immediateTransactionOpen || parent::inTransaction()) {
            throw new PDOException('There is already an active transaction');
        }

        $this->exec('BEGIN IMMEDIATE TRANSACTION');
        $this->immediateTransactionOpen = true;

        return true;
    }

    public function commit(): bool
    {
        if (! $this->immediateTransactionOpen) {
            return parent::commit();
        }

        $this->exec('COMMIT');
        $this->immediateTransactionOpen = false;

        return true;
    }

    public function rollBack(): bool
    {
        if (! $this->immediateTransactionOpen) {
            return parent::rollBack();
        }

        $this->exec('ROLLBACK');
        $this->immediateTransactionOpen = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->immediateTransactionOpen || parent::inTransaction();
    }
}
