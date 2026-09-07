CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(80) NOT NULL UNIQUE,
    email VARCHAR(190) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('Administrator','Operator','Viewer') NOT NULL DEFAULT 'Viewer',
    force_password_change TINYINT(1) NOT NULL DEFAULT 1,
    session_version INT UNSIGNED NOT NULL DEFAULT 1,
    failed_login_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    password_changed_at DATETIME NULL,
    mfa_enabled TINYINT(1) NOT NULL DEFAULT 0,
    mfa_secret TEXT NULL,
    mfa_confirmed_at DATETIME NULL,
    last_login_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remember_tokens (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    selector VARCHAR(24) NOT NULL UNIQUE,
    validator_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    INDEX remember_tokens_user_id_idx (user_id),
    CONSTRAINT remember_tokens_user_fk FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    level VARCHAR(20) NOT NULL,
    action VARCHAR(120) NOT NULL,
    context JSON NULL,
    ip_address VARCHAR(45) NULL,
    session_id_hash CHAR(64) NULL,
    user_agent VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    INDEX audit_logs_created_at_idx (created_at),
    INDEX audit_logs_user_id_idx (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS settings (
    setting_key VARCHAR(120) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS backup_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(40) NOT NULL,
    target VARCHAR(500) NOT NULL,
    archive_path VARCHAR(600) NULL,
    status VARCHAR(40) NOT NULL,
    progress TINYINT UNSIGNED NOT NULL DEFAULT 0,
    message TEXT NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    INDEX backup_jobs_created_at_idx (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS script_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    script_name VARCHAR(190) NOT NULL,
    status VARCHAR(40) NOT NULL,
    output MEDIUMTEXT NULL,
    exit_code INT NULL,
    run_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    finished_at DATETIME NULL,
    INDEX script_runs_created_at_idx (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scheduled_tasks (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    schedule VARCHAR(80) NOT NULL,
    script_name VARCHAR(190) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_run_at DATETIME NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hosting_sites (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    slug VARCHAR(80) NOT NULL,
    domain VARCHAR(190) NULL,
    document_root VARCHAR(600) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'active',
    php_version VARCHAR(40) NULL,
    created_by INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY hosting_sites_slug_unique (slug),
    UNIQUE KEY hosting_sites_domain_unique (domain),
    UNIQUE KEY hosting_sites_document_root_unique (document_root),
    INDEX hosting_sites_status_idx (status),
    INDEX hosting_sites_created_by_idx (created_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rate_limits (
    rate_key CHAR(64) PRIMARY KEY,
    bucket VARCHAR(80) NOT NULL,
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    window_started_at DATETIME NOT NULL,
    blocked_until DATETIME NULL,
    updated_at DATETIME NOT NULL,
    INDEX rate_limits_bucket_idx (bucket),
    INDEX rate_limits_blocked_until_idx (blocked_until)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS metric_samples (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cpu_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    ram_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    disk_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    network_rx BIGINT UNSIGNED NOT NULL DEFAULT 0,
    network_tx BIGINT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    INDEX metric_samples_created_at_idx (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
