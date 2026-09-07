<?php

declare(strict_types=1);

namespace SysAdmin;

final class MysqlService implements \SysAdmin\Contracts\DatabaseOperationsInterface
{
    public function provisionLaravelDatabase(string $slug): array
    {
        $normalized = strtolower(str_replace('-', '_', trim($slug)));
        $normalized = preg_replace('/[^a-z0-9_]+/', '_', $normalized) ?? '';
        $normalized = trim($normalized, '_');
        if ($normalized === '') {
            throw new \InvalidArgumentException('Unable to derive a MySQL database name for this site.');
        }

        $suffix = bin2hex(random_bytes(4));
        $database = 'lp_' . substr($normalized, 0, 52) . '_' . $suffix;
        $username = 'lp_' . substr($normalized, 0, 20) . '_' . $suffix;
        $password = bin2hex(random_bytes(24));
        $connectionHost = (string) config_value('database.host', '127.0.0.1');
        $connectionPort = (int) config_value('database.port', 3306);
        $grantHosts = in_array($connectionHost, ['127.0.0.1', 'localhost', '::1'], true)
            ? ['localhost', '127.0.0.1']
            : ['%'];

        $this->assertIdentifier($database, 'database name');
        $this->assertIdentifier($username, 'database username');

        $receipt = [
            'database' => $database,
            'username' => $username,
            'password' => $password,
            'host' => $connectionHost,
            'port' => $connectionPort,
            'grant_hosts' => [],
            'database_created' => false,
        ];

        $pdo = Database::pdo();
        try {
            $pdo->exec(
                'CREATE DATABASE ' . $this->quoteIdentifier($database)
                . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
            $receipt['database_created'] = true;

            foreach ($grantHosts as $grantHost) {
                $account = $pdo->quote($username) . '@' . $pdo->quote($grantHost);
                $pdo->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $pdo->quote($password));
                $receipt['grant_hosts'][] = $grantHost;
                $pdo->exec(
                    'GRANT ALL PRIVILEGES ON ' . $this->quoteIdentifier($database)
                    . '.* TO ' . $account
                );
            }
        } catch (\Throwable $exception) {
            $this->rollbackLaravelDatabase($receipt);
            throw new \RuntimeException('Unable to provision the site MySQL database.', 0, $exception);
        }

        AuditLogger::info('mysql.laravel_database.create', [
            'database' => $database,
            'username' => $username,
            'grant_hosts' => $grantHosts,
        ]);

        return $receipt;
    }

    public function rollbackLaravelDatabase(array $receipt): void
    {
        $database = (string) ($receipt['database'] ?? '');
        $username = (string) ($receipt['username'] ?? '');
        if (
            !str_starts_with($database, 'lp_')
            || !str_starts_with($username, 'lp_')
            || !preg_match('/^[a-z0-9_]+$/', $database)
            || !preg_match('/^[a-z0-9_]+$/', $username)
        ) {
            return;
        }

        $errors = [];
        try {
            $pdo = Database::pdo();
        } catch (\Throwable $exception) {
            error_log('Unable to connect for Laravel database rollback: ' . $exception->getMessage());
            return;
        }

        foreach (array_reverse((array) ($receipt['grant_hosts'] ?? [])) as $grantHost) {
            if (!in_array($grantHost, ['localhost', '127.0.0.1', '%'], true)) {
                continue;
            }
            try {
                $account = $pdo->quote($username) . '@' . $pdo->quote((string) $grantHost);
                $pdo->exec('DROP USER IF EXISTS ' . $account);
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if (!empty($receipt['database_created'])) {
            try {
                $pdo->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($database));
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
            }
        }

        if ($errors) {
            AuditLogger::error('mysql.laravel_database.rollback_failed', [
                'database' => $database,
                'username' => $username,
                'errors' => $errors,
            ]);
            return;
        }

        AuditLogger::info('mysql.laravel_database.rollback', [
            'database' => $database,
            'username' => $username,
        ]);
    }

    public function deleteManagedSiteDatabase(string $database, string $username, bool $dropDatabase, bool $dropUser): array
    {
        if (
            !str_starts_with($database, 'lp_')
            || !str_starts_with($username, 'lp_')
            || !preg_match('/^[a-z0-9_]+$/', $database)
            || !preg_match('/^[a-z0-9_]+$/', $username)
        ) {
            throw new \InvalidArgumentException('Only MicroSysAdmin-provisioned database resources can be removed automatically.');
        }

        $removedHosts = [];
        $pdo = Database::pdo();
        if ($dropDatabase) {
            $pdo->exec('DROP DATABASE IF EXISTS ' . $this->quoteIdentifier($database));
        }
        if ($dropUser) {
            $statement = $pdo->prepare('SELECT Host FROM mysql.user WHERE User = ?');
            $statement->execute([$username]);
            foreach ($statement->fetchAll() as $row) {
                $host = (string) $row['Host'];
                if (!in_array($host, ['localhost', '127.0.0.1', '::1', '%'], true)) {
                    continue;
                }
                $pdo->exec('DROP USER IF EXISTS ' . $pdo->quote($username) . '@' . $pdo->quote($host));
                $removedHosts[] = $host;
            }
        }
        AuditLogger::warning('mysql.managed_site.cleanup', [
            'database' => $database,
            'username' => $username,
            'database_removed' => $dropDatabase,
            'user_hosts_removed' => $removedHosts,
        ]);
        return [
            'database' => $database,
            'database_removed' => $dropDatabase,
            'username' => $username,
            'user_hosts_removed' => $removedHosts,
        ];
    }

    public function databases(): array
    {
        $sql = "SELECT SCHEMA_NAME AS name FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME NOT IN ('information_schema','performance_schema','mysql','sys') ORDER BY SCHEMA_NAME";
        return Database::pdo()->query($sql)->fetchAll();
    }

    public function tables(string $database = ''): array
    {
        $database = $database ?: (string) config_value('database.name', 'microsysadmin');
        $this->assertIdentifier($database, 'database name');

        $stmt = Database::pdo()->prepare('SELECT TABLE_NAME AS name, TABLE_ROWS AS rows_count, DATA_LENGTH + INDEX_LENGTH AS bytes, ENGINE AS engine, TABLE_COLLATION AS collation FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME');
        $stmt->execute([$database]);
        $rows = $stmt->fetchAll();
        foreach ($rows as &$row) {
            $row['size_human'] = human_bytes((int) $row['bytes']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Choose a useful initial table instead of opening an empty table simply
     * because its name sorts first.
     *
     * @param array<int, array<string, mixed>> $tables
     */
    public static function preferredTable(array $tables): string
    {
        foreach ($tables as $table) {
            $name = (string) ($table['name'] ?? '');
            if ($name !== '' && (int) ($table['rows_count'] ?? 0) > 0) {
                return $name;
            }
        }

        return (string) ($tables[0]['name'] ?? '');
    }

    public function indexes(string $database, string $table): array
    {
        foreach ([$database, $table] as $identifier) {
            $this->assertIdentifier($identifier);
        }
        $stmt = Database::pdo()->prepare('SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, SEQ_IN_INDEX FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY INDEX_NAME, SEQ_IN_INDEX');
        $stmt->execute([$database, $table]);
        return $stmt->fetchAll();
    }

    public function columns(string $database, string $table): array
    {
        $this->assertIdentifier($database, 'database name');
        $this->assertIdentifier($table, 'table name');

        $stmt = Database::pdo()->prepare(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, DATA_TYPE AS data_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, EXTRA AS extra, COLUMN_KEY AS column_key, ORDINAL_POSITION AS ordinal_position
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute([$database, $table]);
        return $stmt->fetchAll();
    }

    public function primaryKey(string $database, string $table): array
    {
        $columns = $this->columns($database, $table);
        return array_values(array_map(
            static fn (array $column): string => (string) $column['name'],
            array_filter($columns, static fn (array $column): bool => (string) $column['column_key'] === 'PRI')
        ));
    }

    public function rows(string $database, string $table, int $limit = 100, int $offset = 0): array
    {
        $this->assertTableExists($database, $table);
        $limit = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $sql = 'SELECT * FROM ' . $this->quoteTable($database, $table) . ' LIMIT ' . $limit . ' OFFSET ' . $offset;
        return Database::pdo()->query($sql)->fetchAll();
    }

    public function rowCount(string $database, string $table): int
    {
        $this->assertTableExists($database, $table);
        return (int) Database::pdo()->query('SELECT COUNT(*) FROM ' . $this->quoteTable($database, $table))->fetchColumn();
    }

    public function createRow(string $database, string $table, array $values, array $nulls = []): array
    {
        $columns = $this->columnsForWrite($database, $table);
        $payload = $this->payloadForColumns($columns, $values, $nulls, true);
        $pdo = Database::pdo();

        if (!$payload) {
            $sql = 'INSERT INTO ' . $this->quoteTable($database, $table) . ' () VALUES ()';
            $stmt = $pdo->prepare($sql);
            $stmt->execute();
        } else {
            $names = array_keys($payload);
            $sql = 'INSERT INTO ' . $this->quoteTable($database, $table)
                . ' (' . implode(', ', array_map([$this, 'quoteIdentifier'], $names)) . ')'
                . ' VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')';
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array_values($payload));
        }

        $affected = $stmt->rowCount();
        $insertId = $pdo->lastInsertId();
        AuditLogger::warning('mysql.row.create', ['database' => $database, 'table' => $table]);
        return ['affected' => $affected, 'insert_id' => $insertId];
    }

    public function updateRow(string $database, string $table, array $key, array $values, array $nulls = []): array
    {
        $columns = $this->columnsForWrite($database, $table);
        $primaryKey = $this->primaryKey($database, $table);

        $payload = $this->payloadForColumns($columns, $values, $nulls, false, $primaryKey);
        if (!$payload) {
            throw new \InvalidArgumentException('No editable values were submitted.');
        }

        $params = array_values($payload);
        $where = $primaryKey
            ? $this->primaryKeyWhere($primaryKey, $key, $params)
            : $this->rowValueWhere($columns, $key, $params);

        $sql = 'UPDATE ' . $this->quoteTable($database, $table)
            . ' SET ' . implode(', ', array_map(fn (string $name): string => $this->quoteIdentifier($name) . ' = ?', array_keys($payload)))
            . ' WHERE ' . $where
            . ' LIMIT 1';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        AuditLogger::warning('mysql.row.update', ['database' => $database, 'table' => $table, 'key' => $key]);
        return ['affected' => $stmt->rowCount()];
    }

    public function deleteRow(string $database, string $table, array $key): array
    {
        $this->assertTableExists($database, $table);
        $primaryKey = $this->primaryKey($database, $table);

        $params = [];
        $columns = $primaryKey ? [] : $this->columns($database, $table);
        $where = $primaryKey
            ? $this->primaryKeyWhere($primaryKey, $key, $params)
            : $this->rowValueWhere($columns, $key, $params);

        $sql = 'DELETE FROM ' . $this->quoteTable($database, $table)
            . ' WHERE ' . $where
            . ' LIMIT 1';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);

        AuditLogger::warning('mysql.row.delete', ['database' => $database, 'table' => $table, 'key' => $key]);
        return ['affected' => $stmt->rowCount()];
    }

    public function importSql(string $database, array $upload): array
    {
        SecurityProfile::assertAllowed('sql_import');
        $this->assertIdentifier($database, 'database name');
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('SQL upload failed.');
        }
        $name = (string) ($upload['name'] ?? '');
        if (!str_ends_with(strtolower($name), '.sql')) {
            throw new \InvalidArgumentException('Only .sql imports are allowed.');
        }
        $sql = file_get_contents((string) $upload['tmp_name']);
        if ($sql === false || trim($sql) === '') {
            throw new \RuntimeException('SQL file is empty.');
        }

        $pdo = Database::pdo();
        $pdo->exec('USE `' . $database . '`');
        $pdo->exec($sql);
        \SysAdmin\AuditLogger::warning('mysql.import', ['database' => $database, 'file' => $name]);

        return ['database' => $database, 'file' => $name];
    }

    private function assertIdentifier(string $identifier, string $label = 'identifier'): void
    {
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $identifier)) {
            throw new \InvalidArgumentException('Invalid ' . $label . '.');
        }
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    private function quoteTable(string $database, string $table): string
    {
        $this->assertIdentifier($database, 'database name');
        $this->assertIdentifier($table, 'table name');
        return $this->quoteIdentifier($database) . '.' . $this->quoteIdentifier($table);
    }

    private function assertTableExists(string $database, string $table): void
    {
        $this->assertIdentifier($database, 'database name');
        $this->assertIdentifier($table, 'table name');
        $stmt = Database::pdo()->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
        $stmt->execute([$database, $table]);
        if ((int) $stmt->fetchColumn() === 0) {
            throw new \InvalidArgumentException('Unknown MySQL table.');
        }
    }

    private function columnsForWrite(string $database, string $table): array
    {
        $columns = $this->columns($database, $table);
        if (!$columns) {
            throw new \InvalidArgumentException('Unknown MySQL table.');
        }
        return $columns;
    }

    private function payloadForColumns(array $columns, array $values, array $nulls, bool $forCreate, array $skipColumns = []): array
    {
        $skip = array_fill_keys($skipColumns, true);
        $payload = [];

        foreach ($columns as $column) {
            $name = (string) $column['name'];
            $extra = strtolower((string) ($column['extra'] ?? ''));
            if (isset($skip[$name]) || str_contains($extra, 'generated')) {
                continue;
            }
            if (!array_key_exists($name, $values) && !array_key_exists($name, $nulls)) {
                continue;
            }
            if ($forCreate && str_contains($extra, 'auto_increment') && !array_key_exists($name, $nulls) && trim((string) ($values[$name] ?? '')) === '') {
                continue;
            }
            if (array_key_exists($name, $nulls)) {
                if ((string) $column['is_nullable'] !== 'YES') {
                    throw new \InvalidArgumentException($name . ' cannot be NULL.');
                }
                $payload[$name] = null;
                continue;
            }

            $value = $values[$name] ?? null;
            if ($value === null || $this->emptyValueShouldBeNull($column, $value)) {
                if ((string) $column['is_nullable'] !== 'YES') {
                    throw new \InvalidArgumentException($name . ' cannot be NULL.');
                }
                $payload[$name] = null;
                continue;
            }

            $payload[$name] = (string) $value;
        }

        return $payload;
    }

    private function emptyValueShouldBeNull(array $column, mixed $value): bool
    {
        if ((string) $column['is_nullable'] !== 'YES' || !is_scalar($value) || trim((string) $value) !== '') {
            return false;
        }

        return !in_array(strtolower((string) $column['data_type']), [
            'char',
            'varchar',
            'tinytext',
            'text',
            'mediumtext',
            'longtext',
            'binary',
            'varbinary',
            'tinyblob',
            'blob',
            'mediumblob',
            'longblob',
            'enum',
            'set',
        ], true);
    }

    private function primaryKeyWhere(array $primaryKey, array $key, array &$params): string
    {
        $where = [];
        foreach ($primaryKey as $column) {
            if (!array_key_exists($column, $key)) {
                throw new \InvalidArgumentException('Missing primary key value.');
            }
            $where[] = $this->quoteIdentifier($column) . ' = ?';
            $params[] = $key[$column];
        }
        return implode(' AND ', $where);
    }

    private function rowValueWhere(array $columns, array $key, array &$params): string
    {
        if (!$columns || !$key) {
            throw new \InvalidArgumentException('Missing row identity values.');
        }

        $where = [];
        foreach ($columns as $column) {
            $name = (string) $column['name'];
            if (!array_key_exists($name, $key)) {
                throw new \InvalidArgumentException('Missing row identity value for ' . $name . '.');
            }

            $where[] = $this->quoteIdentifier($name) . ' <=> ?';
            $params[] = $key[$name];
        }

        return implode(' AND ', $where);
    }
}
