<?php

declare(strict_types=1);

return static function (\PDO $pdo): void {
    $statement = $pdo->query(
        "SELECT COLUMN_NAME
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'machines'
         ORDER BY ORDINAL_POSITION"
    );
    $columns = array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN));

    if (in_array('microsysadmin_version', $columns, true)) {
        return;
    }

    $previousVersionColumns = array_values(array_filter(
        $columns,
        static fn (string $column): bool => $column !== 'php_version' && str_ends_with($column, '_version')
    ));

    if (count($previousVersionColumns) === 1) {
        $previous = str_replace('`', '``', $previousVersionColumns[0]);
        $pdo->exec(
            "ALTER TABLE machines CHANGE COLUMN `{$previous}` microsysadmin_version VARCHAR(80) NOT NULL"
        );
        return;
    }

    if ($previousVersionColumns !== []) {
        throw new \RuntimeException('Unable to identify the previous machine version column safely.');
    }

    $pdo->exec(
        "ALTER TABLE machines
         ADD COLUMN microsysadmin_version VARCHAR(80) NOT NULL DEFAULT '' AFTER architecture"
    );
};
