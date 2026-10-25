<?php

declare(strict_types=1);

namespace Astral\Worker\Database;

use PDO;

/**
 * Source unique du schéma (SQLite + MySQL), utilisée par les migrations et par les tests.
 * Les dates sont des timestamps UNIX (entiers) : aucune différence de dialecte à gérer.
 */
final class Schema
{
    public static function assertIdentifier(string $name): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new \InvalidArgumentException("Nom de table invalide : {$name}");
        }
    }

    public static function createJobsTable(PDO $pdo, string $table = 'jobs'): void
    {
        self::assertIdentifier($table);

        if (self::isSqlite($pdo)) {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS {$table} (
                    id           INTEGER PRIMARY KEY AUTOINCREMENT,
                    queue        TEXT    NOT NULL DEFAULT 'default',
                    job_class    TEXT    NOT NULL,
                    payload      TEXT    NOT NULL,
                    attempts     INTEGER NOT NULL DEFAULT 0,
                    available_at INTEGER NOT NULL,
                    reserved_at  INTEGER NULL,
                    created_at   INTEGER NOT NULL
                )
            SQL);
            $pdo->exec("CREATE INDEX IF NOT EXISTS {$table}_queue_available_idx ON {$table} (queue, available_at)");

            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS `{$table}` (
                `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `queue`        VARCHAR(100)    NOT NULL DEFAULT 'default',
                `job_class`    VARCHAR(255)    NOT NULL,
                `payload`      LONGTEXT        NOT NULL,
                `attempts`     INT UNSIGNED    NOT NULL DEFAULT 0,
                `available_at` INT UNSIGNED    NOT NULL,
                `reserved_at`  INT UNSIGNED    NULL,
                `created_at`   INT UNSIGNED    NOT NULL,
                PRIMARY KEY (`id`),
                KEY `{$table}_queue_available_idx` (`queue`, `available_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public static function createFailedJobsTable(PDO $pdo, string $table = 'failed_jobs'): void
    {
        self::assertIdentifier($table);

        if (self::isSqlite($pdo)) {
            $pdo->exec(<<<SQL
                CREATE TABLE IF NOT EXISTS {$table} (
                    id        INTEGER PRIMARY KEY AUTOINCREMENT,
                    queue     TEXT    NOT NULL DEFAULT 'default',
                    job_class TEXT    NOT NULL,
                    payload   TEXT    NOT NULL,
                    attempts  INTEGER NOT NULL DEFAULT 0,
                    exception TEXT    NOT NULL,
                    failed_at INTEGER NOT NULL
                )
            SQL);

            return;
        }

        $pdo->exec(<<<SQL
            CREATE TABLE IF NOT EXISTS `{$table}` (
                `id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `queue`     VARCHAR(100)    NOT NULL DEFAULT 'default',
                `job_class` VARCHAR(255)    NOT NULL,
                `payload`   LONGTEXT        NOT NULL,
                `attempts`  INT UNSIGNED    NOT NULL DEFAULT 0,
                `exception` LONGTEXT        NOT NULL,
                `failed_at` INT UNSIGNED    NOT NULL,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        SQL);
    }

    public static function dropTable(PDO $pdo, string $table): void
    {
        self::assertIdentifier($table);
        $pdo->exec("DROP TABLE IF EXISTS {$table}");
    }

    private static function isSqlite(PDO $pdo): bool
    {
        return $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    }
}
