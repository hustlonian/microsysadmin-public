<?php

declare(strict_types=1);

namespace SysAdmin;

use PDO;

final class MigrationRunner
{
    /**
     * Published migrations are normally immutable. These reviewed transitions
     * pair the product rename with a follow-up migration so installations that
     * applied an earlier foundation checksum can upgrade safely.
     *
     * @var array<string, array<string, string>>
     */
    private const COMPATIBLE_CHECKSUM_TRANSITIONS = [
        '202607270001_commercial_foundation.php' => [
            '2a0f6c7b445477770643679ee60c4f18d11e4dbf8e610b6fca3f42cf7360d686'
                => 'e86f9e4fe7124cdc25836cc6934e7635636ee40b370aee8a4325ab574fbfb412',
            '9e441c987a920a1f02fd669ca63d25ae499be11db1556f8d7a3ab0908311990e'
                => 'e86f9e4fe7124cdc25836cc6934e7635636ee40b370aee8a4325ab574fbfb412',
        ],
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @return array<int, string>
     */
    public function migrate(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                migration VARCHAR(190) PRIMARY KEY,
                checksum CHAR(64) NOT NULL,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );

        $applied = [];
        foreach ($this->files() as $file) {
            $name = basename($file);
            $checksum = hash_file('sha256', $file);
            $existing = $this->checksum($name);
            if ($existing !== null) {
                if (
                    !hash_equals($existing, $checksum)
                    && !$this->isCompatibleChecksumTransition($name, $existing, $checksum)
                ) {
                    throw new \RuntimeException("Applied migration {$name} has changed.");
                }
                continue;
            }

            $migration = require $file;
            if (!is_callable($migration)) {
                throw new \RuntimeException("Migration {$name} must return a callable.");
            }

            $this->pdo->beginTransaction();
            try {
                $migration($this->pdo);
                $statement = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (migration, checksum, applied_at) VALUES (?, ?, ?)'
                );
                $statement->execute([$name, $checksum, date('Y-m-d H:i:s')]);
                if ($this->pdo->inTransaction()) {
                    $this->pdo->commit();
                }
                $applied[] = $name;
            } catch (\Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw new \RuntimeException("Migration {$name} failed: {$exception->getMessage()}", 0, $exception);
            }
        }

        return $applied;
    }

    public function fingerprint(): string
    {
        $parts = [];
        foreach ($this->files() as $file) {
            $parts[] = basename($file) . ':' . hash_file('sha256', $file);
        }
        return hash('sha256', implode('|', $parts));
    }

    /**
     * @return array<int, string>
     */
    public function pending(): array
    {
        $pending = [];
        foreach ($this->files() as $file) {
            $name = basename($file);
            if ($this->checksum($name) === null) {
                $pending[] = $name;
            }
        }
        return $pending;
    }

    /**
     * @return array<int, string>
     */
    private function files(): array
    {
        $files = glob(APP_ROOT . '/database/migrations/*.php') ?: [];
        sort($files, SORT_STRING);
        return $files;
    }

    private function checksum(string $migration): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT checksum FROM schema_migrations WHERE migration = ? LIMIT 1'
        );
        $statement->execute([$migration]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private function isCompatibleChecksumTransition(
        string $migration,
        string $appliedChecksum,
        string $currentChecksum
    ): bool {
        $expectedCurrent = self::COMPATIBLE_CHECKSUM_TRANSITIONS[$migration][$appliedChecksum] ?? null;
        return is_string($expectedCurrent) && hash_equals($expectedCurrent, $currentChecksum);
    }
}
