<?php

declare(strict_types=1);

return static function (\PDO $pdo): void {
    $statements = [
        "CREATE TABLE IF NOT EXISTS doctor_executions (
            execution_id VARCHAR(64) PRIMARY KEY,
            mode VARCHAR(40) NOT NULL,
            status VARCHAR(40) NOT NULL,
            project_path VARCHAR(1000) NULL,
            environment_json LONGTEXT NULL,
            options_json LONGTEXT NULL,
            result_json LONGTEXT NULL,
            before_score_json TEXT NULL,
            after_score_json TEXT NULL,
            initiated_by INT UNSIGNED NULL,
            started_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            INDEX doctor_executions_started_idx (started_at),
            INDEX doctor_executions_status_idx (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS doctor_approvals (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            execution_id VARCHAR(64) NOT NULL,
            repair_id VARCHAR(40) NOT NULL,
            plan_hash CHAR(64) NOT NULL,
            approved_by INT UNSIGNED NOT NULL,
            approved_at DATETIME NOT NULL,
            consumed_at DATETIME NULL,
            UNIQUE KEY doctor_approval_unique (execution_id, repair_id, plan_hash),
            INDEX doctor_approval_execution_idx (execution_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS doctor_audit_events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            execution_id VARCHAR(64) NULL,
            level VARCHAR(20) NOT NULL,
            event_type VARCHAR(120) NOT NULL,
            message TEXT NOT NULL,
            context_json LONGTEXT NULL,
            created_at DATETIME NOT NULL,
            INDEX doctor_audit_execution_idx (execution_id, id),
            INDEX doctor_audit_type_idx (event_type, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS doctor_reports (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            execution_id VARCHAR(64) NOT NULL,
            format VARCHAR(30) NOT NULL,
            report_path VARCHAR(1000) NULL,
            content_hash CHAR(64) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX doctor_reports_execution_idx (execution_id, format)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }
};
