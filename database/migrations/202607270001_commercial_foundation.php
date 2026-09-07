<?php

declare(strict_types=1);

return static function (\PDO $pdo): void {
    $tables = [
        "CREATE TABLE IF NOT EXISTS organizations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            name VARCHAR(190) NOT NULL,
            slug VARCHAR(120) NOT NULL UNIQUE,
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS organization_members (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            organization_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            organization_role VARCHAR(40) NOT NULL DEFAULT 'member',
            status VARCHAR(30) NOT NULL DEFAULT 'active',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY organization_members_unique (organization_id, user_id),
            INDEX organization_members_user_idx (user_id),
            INDEX organization_members_organization_idx (organization_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS machine_groups (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            organization_id BIGINT UNSIGNED NOT NULL,
            uuid CHAR(36) NOT NULL UNIQUE,
            name VARCHAR(190) NOT NULL,
            description TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX machine_groups_organization_idx (organization_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS machines (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            organization_id BIGINT UNSIGNED NOT NULL,
            machine_group_id BIGINT UNSIGNED NULL,
            machine_uuid CHAR(36) NOT NULL UNIQUE,
            installation_uuid CHAR(36) NOT NULL UNIQUE,
            display_name VARCHAR(190) NOT NULL,
            hostname VARCHAR(255) NOT NULL,
            operating_system VARCHAR(500) NULL,
            architecture VARCHAR(80) NULL,
            microsysadmin_version VARCHAR(80) NOT NULL,
            php_version VARCHAR(80) NULL,
            xampp_root VARCHAR(600) NULL,
            document_roots JSON NULL,
            installed_at DATETIME NOT NULL,
            last_update_at DATETIME NULL,
            license_status VARCHAR(40) NOT NULL DEFAULT 'unverified',
            connection_status VARCHAR(40) NOT NULL DEFAULT 'disconnected',
            last_seen_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX machines_organization_idx (organization_id),
            INDEX machines_group_idx (machine_group_id),
            INDEX machines_connection_idx (connection_status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS plans (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(60) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            description TEXT NULL,
            active TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS entitlements (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            entitlement_key VARCHAR(120) NOT NULL UNIQUE,
            value_type VARCHAR(30) NOT NULL DEFAULT 'boolean',
            description TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS plan_entitlements (
            plan_id BIGINT UNSIGNED NOT NULL,
            entitlement_id BIGINT UNSIGNED NOT NULL,
            entitlement_value VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (plan_id, entitlement_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS licenses (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            organization_id BIGINT UNSIGNED NOT NULL,
            plan_id BIGINT UNSIGNED NOT NULL,
            license_key_hash CHAR(64) NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'unverified',
            source VARCHAR(40) NOT NULL DEFAULT 'local',
            starts_at DATETIME NULL,
            expires_at DATETIME NULL,
            updates_until DATETIME NULL,
            last_verified_at DATETIME NULL,
            grace_until DATETIME NULL,
            metadata JSON NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX licenses_organization_idx (organization_id),
            INDEX licenses_status_idx (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS license_entitlements (
            license_id BIGINT UNSIGNED NOT NULL,
            entitlement_id BIGINT UNSIGNED NOT NULL,
            entitlement_value VARCHAR(500) NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (license_id, entitlement_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS machine_licenses (
            machine_id BIGINT UNSIGNED NOT NULL,
            license_id BIGINT UNSIGNED NOT NULL,
            assigned_at DATETIME NOT NULL,
            PRIMARY KEY (machine_id, license_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS invitations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            organization_id BIGINT UNSIGNED NOT NULL,
            email VARCHAR(190) NOT NULL,
            organization_role VARCHAR(40) NOT NULL DEFAULT 'member',
            token_hash CHAR(64) NOT NULL UNIQUE,
            invited_by INT UNSIGNED NULL,
            expires_at DATETIME NOT NULL,
            accepted_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            INDEX invitations_organization_idx (organization_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS support_status (
            organization_id BIGINT UNSIGNED PRIMARY KEY,
            level VARCHAR(40) NOT NULL DEFAULT 'community',
            active_until DATETIME NULL,
            metadata JSON NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS agent_credentials (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            machine_id BIGINT UNSIGNED NOT NULL,
            key_id VARCHAR(80) NOT NULL UNIQUE,
            secret_encrypted TEXT NOT NULL,
            last_used_at DATETIME NULL,
            expires_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            INDEX agent_credentials_machine_idx (machine_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS agent_requests (
            request_id VARCHAR(100) PRIMARY KEY,
            machine_id BIGINT UNSIGNED NOT NULL,
            key_id VARCHAR(80) NOT NULL,
            requested_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            status VARCHAR(30) NOT NULL,
            audit_correlation_id VARCHAR(100) NULL,
            INDEX agent_requests_requested_idx (requested_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS hub_connections (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            organization_id BIGINT UNSIGNED NOT NULL,
            machine_id BIGINT UNSIGNED NOT NULL,
            hub_url VARCHAR(500) NOT NULL,
            remote_machine_id VARCHAR(190) NULL,
            credential_encrypted TEXT NULL,
            data_sharing JSON NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'disconnected',
            last_heartbeat_at DATETIME NULL,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            UNIQUE KEY hub_connections_machine_unique (machine_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS jobs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            organization_id BIGINT UNSIGNED NULL,
            machine_id BIGINT UNSIGNED NULL,
            type VARCHAR(120) NOT NULL,
            payload JSON NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT 'queued',
            progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
            output_summary MEDIUMTEXT NULL,
            error_message TEXT NULL,
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            max_attempts SMALLINT UNSIGNED NOT NULL DEFAULT 3,
            timeout_seconds INT UNSIGNED NOT NULL DEFAULT 600,
            available_at DATETIME NOT NULL,
            reserved_at DATETIME NULL,
            started_at DATETIME NULL,
            completed_at DATETIME NULL,
            cancelled_at DATETIME NULL,
            owned_by INT UNSIGNED NULL,
            audit_correlation_id VARCHAR(100) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX jobs_queue_idx (status, available_at),
            INDEX jobs_machine_idx (machine_id),
            INDEX jobs_owner_idx (owned_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS api_tokens (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            organization_id BIGINT UNSIGNED NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(190) NOT NULL,
            token_prefix VARCHAR(24) NOT NULL,
            token_hash CHAR(64) NOT NULL UNIQUE,
            scopes JSON NOT NULL,
            rate_limit INT UNSIGNED NOT NULL DEFAULT 60,
            expires_at DATETIME NULL,
            last_used_at DATETIME NULL,
            revoked_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            INDEX api_tokens_prefix_idx (token_prefix),
            INDEX api_tokens_organization_idx (organization_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS events (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            uuid CHAR(36) NOT NULL UNIQUE,
            organization_id BIGINT UNSIGNED NULL,
            machine_id BIGINT UNSIGNED NULL,
            event_type VARCHAR(120) NOT NULL,
            payload JSON NOT NULL,
            audit_correlation_id VARCHAR(100) NULL,
            occurred_at DATETIME NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX events_type_idx (event_type, occurred_at),
            INDEX events_organization_idx (organization_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS webhook_endpoints (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            organization_id BIGINT UNSIGNED NOT NULL,
            url VARCHAR(800) NOT NULL,
            secret_encrypted TEXT NOT NULL,
            event_types JSON NOT NULL,
            enabled TINYINT(1) NOT NULL DEFAULT 0,
            timeout_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 10,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX webhook_endpoints_organization_idx (organization_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS webhook_deliveries (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            webhook_endpoint_id BIGINT UNSIGNED NOT NULL,
            event_id BIGINT UNSIGNED NOT NULL,
            delivery_uuid CHAR(36) NOT NULL UNIQUE,
            status VARCHAR(30) NOT NULL DEFAULT 'queued',
            attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            response_code SMALLINT UNSIGNED NULL,
            response_summary TEXT NULL,
            next_attempt_at DATETIME NULL,
            delivered_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            INDEX webhook_deliveries_queue_idx (status, next_attempt_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS update_history (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            machine_id BIGINT UNSIGNED NULL,
            from_version VARCHAR(80) NOT NULL,
            to_version VARCHAR(80) NOT NULL,
            channel VARCHAR(30) NOT NULL,
            status VARCHAR(40) NOT NULL,
            manifest_checksum CHAR(64) NULL,
            package_checksum CHAR(64) NULL,
            backup_path VARCHAR(800) NULL,
            rollback_path VARCHAR(800) NULL,
            message TEXT NULL,
            started_at DATETIME NOT NULL,
            completed_at DATETIME NULL,
            initiated_by INT UNSIGNED NULL,
            audit_correlation_id VARCHAR(100) NULL,
            INDEX update_history_started_idx (started_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS mfa_recovery_codes (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            code_hash VARCHAR(255) NOT NULL,
            used_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            INDEX mfa_recovery_codes_user_idx (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($tables as $statement) {
        $pdo->exec($statement);
    }

    $alters = [
        'ALTER TABLE users ADD COLUMN organization_id BIGINT UNSIGNED NULL AFTER id',
        'ALTER TABLE users ADD COLUMN is_disabled TINYINT(1) NOT NULL DEFAULT 0 AFTER role',
        'ALTER TABLE audit_logs ADD COLUMN organization_id BIGINT UNSIGNED NULL AFTER id',
        'ALTER TABLE audit_logs ADD COLUMN machine_id BIGINT UNSIGNED NULL AFTER organization_id',
        'ALTER TABLE audit_logs ADD COLUMN request_id VARCHAR(100) NULL AFTER session_id_hash',
        'ALTER TABLE backup_jobs ADD COLUMN organization_id BIGINT UNSIGNED NULL AFTER id',
        'ALTER TABLE backup_jobs ADD COLUMN machine_id BIGINT UNSIGNED NULL AFTER organization_id',
        'ALTER TABLE backup_jobs ADD COLUMN job_uuid CHAR(36) NULL AFTER machine_id',
        'ALTER TABLE script_runs ADD COLUMN organization_id BIGINT UNSIGNED NULL AFTER id',
        'ALTER TABLE script_runs ADD COLUMN machine_id BIGINT UNSIGNED NULL AFTER organization_id',
        'ALTER TABLE scheduled_tasks ADD COLUMN organization_id BIGINT UNSIGNED NULL AFTER id',
        'ALTER TABLE scheduled_tasks ADD COLUMN machine_id BIGINT UNSIGNED NULL AFTER organization_id',
        'ALTER TABLE hosting_sites ADD COLUMN organization_id BIGINT UNSIGNED NULL AFTER id',
        'ALTER TABLE hosting_sites ADD COLUMN machine_id BIGINT UNSIGNED NULL AFTER organization_id',
        'ALTER TABLE metric_samples ADD COLUMN machine_id BIGINT UNSIGNED NULL AFTER id',
    ];

    foreach ($alters as $statement) {
        try {
            $pdo->exec($statement);
        } catch (\PDOException $exception) {
            $driverCode = (int) ($exception->errorInfo[1] ?? 0);
            if (!in_array($driverCode, [1060, 1061], true)) {
                throw $exception;
            }
        }
    }
};
