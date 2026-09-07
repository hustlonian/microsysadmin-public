<?php

declare(strict_types=1);

namespace SysAdmin;

final class HostingService implements \SysAdmin\Contracts\WebsiteProvisioningInterface
{
    private const SITE_SIZE_CACHE_TTL = 300;

    private ?array $siteSizeCache = null;

    public function overview(bool $includeSizes = true): array
    {
        $roots = $this->roots();
        $sites = $this->sites($includeSizes, $roots);
        $managed = 0;
        foreach ($sites as $site) {
            if (!empty($site['managed'])) {
                $managed++;
            }
        }

        return [
            'sites' => $sites,
            'roots' => $roots,
            'summary' => [
                'sites' => count($sites),
                'managed' => $managed,
                'discovered' => count($sites) - $managed,
                'writable_roots' => count(array_filter($roots, static fn (array $root): bool => !empty($root['writable']))),
            ],
        ];
    }

    public function createSite(string $name, string $root = '', string $domain = ''): array
    {
        $displayName = preg_replace('/\s+/', ' ', trim($name)) ?? trim($name);
        $slug = $this->slug($displayName);
        if ($this->isExcludedSiteName($slug)) {
            throw new \InvalidArgumentException('This site name is reserved.');
        }

        $root = $this->resolveRoot($root);
        if (!is_writable($root)) {
            throw new \RuntimeException('Website root is not writable by the web server user.');
        }

        $path = PathGuard::assertAllowed($root . DIRECTORY_SEPARATOR . $slug);
        if (file_exists($path) || is_link($path)) {
            throw new \InvalidArgumentException(
                'A site folder with this name already exists. Use the site Update action to manage it.'
            );
        }

        $domain = $this->normalizeDomain($domain);
        $pdo = Database::pdo();
        $existing = $pdo->prepare(
            'SELECT * FROM hosting_sites
             WHERE organization_id = ? AND machine_id = ?
               AND (slug = ? OR document_root = ? OR domain = ?)
             LIMIT 1'
        );
        $existing->execute([
            CommercialContext::organizationId(),
            CommercialContext::machineId(),
            $slug,
            $path,
            $domain !== '' ? $domain : null,
        ]);
        $existingSite = $existing->fetch();
        if ($existingSite) {
            throw new \InvalidArgumentException('A managed site already uses that name, domain, or document root.');
        }

        $appUrl = rtrim($this->urlForPath($path), '/');
        if ($appUrl === '') {
            $appUrl = 'http://localhost/' . rawurlencode($slug);
        }

        $mysql = new MysqlService();
        $database = $mysql->provisionLaravelDatabase($slug);
        $provisioner = new LaravelSiteProvisioner();
        try {
            $provisioner->provision($path, $displayName, $appUrl, $database);
        } catch (\Throwable $exception) {
            $mysql->rollbackLaravelDatabase($database);
            (new EventBus())->dispatch('site.provisioning_failed', ['name' => $displayName, 'slug' => $slug]);
            throw $exception;
        }

        $now = date('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'INSERT INTO hosting_sites (organization_id, machine_id, name, slug, domain, document_root, status, php_version, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        try {
            $stmt->execute([
                CommercialContext::organizationId(),
                CommercialContext::machineId(),
                $displayName,
                $slug,
                $domain !== '' ? $domain : null,
                $path,
                'active',
                PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                Auth::id(),
                $now,
                $now,
            ]);
        } catch (\Throwable $exception) {
            $this->rollbackCreatedSite($path);
            $mysql->rollbackLaravelDatabase($database);
            throw $exception;
        }

        AuditLogger::info('hosting.site.create', [
            'name' => $displayName,
            'slug' => $slug,
            'path' => $path,
            'domain' => $domain,
            'created_directory' => true,
            'framework' => 'laravel',
            'database' => $database['database'],
            'database_user' => $database['username'],
        ]);
        (new EventBus())->dispatch('site.provisioned', [
            'name' => $displayName,
            'slug' => $slug,
            'domain' => $domain,
        ]);

        $created = $pdo->prepare(
            'SELECT * FROM hosting_sites
             WHERE document_root = ? AND organization_id = ? AND machine_id = ? LIMIT 1'
        );
        $created->execute([$path, CommercialContext::organizationId(), CommercialContext::machineId()]);
        $site = $created->fetch() ?: [
            'id' => null,
            'name' => $displayName,
            'slug' => $slug,
            'domain' => $domain,
            'document_root' => $path,
            'status' => 'active',
            'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return ['site' => $this->sitePayload($site + ['managed' => true])];
    }

    public function updateSite(string $documentRoot, string $name, string $domain = '', string $status = 'active', bool $renameFolder = false): array
    {
        $path = $this->resolveSiteManagePath($documentRoot);
        $site = $this->findSiteByPath($path);
        if (!$site) {
            throw new \InvalidArgumentException('Selected site is not listed on the hosting page.');
        }
        if (empty($site['editable'])) {
            throw new \InvalidArgumentException('This site cannot be edited from the hosting page.');
        }

        $displayName = trim($name);
        if ($displayName === '') {
            throw new \InvalidArgumentException('Site name is required.');
        }

        $slug = $this->slug($displayName);
        $domain = $this->normalizeDomain($domain);
        $status = $this->normalizeStatus($status);
        $targetPath = $path;
        $renamedFolder = false;

        if ($renameFolder && basename($path) !== $slug) {
            $targetPath = dirname($path) . DIRECTORY_SEPARATOR . $slug;
            PathGuard::assertAllowed($targetPath);
            if (!$this->isSiteDestinationPath($targetPath)) {
                throw new \InvalidArgumentException('Folder rename target is not a configured website root site path.');
            }
            if (file_exists($targetPath)) {
                throw new \RuntimeException('A folder with the new site name already exists.');
            }
            $this->assertManagedSiteMetadataAvailable($site, $slug, $domain, $targetPath);
            $targetPath = (new FileVault(true))->rename($path, $slug);
            $renamedFolder = true;
        } else {
            $this->assertManagedSiteMetadataAvailable($site, $slug, $domain, $targetPath);
        }

        $now = date('Y-m-d H:i:s');
        $pdo = Database::pdo();
        if ($site['id'] !== null) {
            $stmt = $pdo->prepare(
                'UPDATE hosting_sites
                 SET name = ?, slug = ?, domain = ?, document_root = ?, status = ?, php_version = ?, updated_at = ?
                 WHERE id = ? AND organization_id = ? AND machine_id = ?'
            );
            $stmt->execute([
                $displayName,
                $slug,
                $domain !== '' ? $domain : null,
                $targetPath,
                $status,
                PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                $now,
                (int) $site['id'],
                CommercialContext::organizationId(),
                CommercialContext::machineId(),
            ]);
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO hosting_sites (
                    organization_id, machine_id, name, slug, domain, document_root,
                    status, php_version, created_by, created_at, updated_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                CommercialContext::organizationId(),
                CommercialContext::machineId(),
                $displayName,
                $slug,
                $domain !== '' ? $domain : null,
                $targetPath,
                $status,
                PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                Auth::id(),
                $now,
                $now,
            ]);
        }

        AuditLogger::info('hosting.site.update', [
            'name' => $displayName,
            'slug' => $slug,
            'from_path' => $path,
            'path' => $targetPath,
            'domain' => $domain,
            'status' => $status,
            'renamed_folder' => $renamedFolder,
        ]);

        $updated = $pdo->prepare(
            'SELECT * FROM hosting_sites
             WHERE document_root = ? AND organization_id = ? AND machine_id = ? LIMIT 1'
        );
        $updated->execute([$targetPath, CommercialContext::organizationId(), CommercialContext::machineId()]);
        $updatedSite = $updated->fetch() ?: [
            'id' => null,
            'name' => $displayName,
            'slug' => $slug,
            'domain' => $domain,
            'document_root' => $targetPath,
            'status' => $status,
            'php_version' => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        return [
            'site' => $this->sitePayload($updatedSite + ['managed' => true]),
            'renamed_folder' => $renamedFolder,
        ];
    }

    public function deleteSite(string $documentRoot, bool $cleanupDatabase = false, bool $cleanupDatabaseUser = false): array
    {
        $path = $this->resolveSiteManagePath($documentRoot);
        $site = $this->findSiteByPath($path);
        if (!$site) {
            throw new \InvalidArgumentException('Selected site is not listed on the hosting page.');
        }
        if (empty($site['deletable'])) {
            throw new \InvalidArgumentException('This site cannot be deleted from the hosting page.');
        }

        $database = $this->managedDatabaseFromEnvironment($path);
        if (($cleanupDatabase || $cleanupDatabaseUser) && $database === null) {
            throw new \InvalidArgumentException('No MicroSysAdmin-provisioned database identity was found for this site.');
        }

        $trash = (new FileVault(true))->delete($path);
        $removedManagedRows = $this->removeManagedSite($path, $site['id'] !== null ? (int) $site['id'] : null);
        $databaseCleanup = null;
        if ($database !== null && ($cleanupDatabase || $cleanupDatabaseUser)) {
            $databaseCleanup = (new MysqlService())->deleteManagedSiteDatabase(
                $database['database'],
                $database['username'],
                $cleanupDatabase,
                $cleanupDatabaseUser
            );
        }

        AuditLogger::warning('hosting.site.delete', [
            'name' => $site['name'],
            'path' => $path,
            'trash' => $trash,
            'managed' => !empty($site['managed']),
            'removed_managed_rows' => $removedManagedRows,
        ]);

        return [
            'site' => [
                'id' => $site['id'],
                'name' => $site['name'],
                'document_root' => $path,
                'managed' => !empty($site['managed']),
            ],
            'trash' => $trash,
            'removed_managed_rows' => $removedManagedRows,
            'database_cleanup' => $databaseCleanup,
        ];
    }

    private function managedDatabaseFromEnvironment(string $path): ?array
    {
        $env = $path . '/.env';
        if (!is_readable($env)) {
            return null;
        }
        $values = [];
        foreach (file($env, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            if (in_array($key, ['DB_CONNECTION', 'DB_DATABASE', 'DB_USERNAME'], true)) {
                $values[$key] = trim($value, " \t\n\r\0\x0B\"'");
            }
        }
        $database = (string) ($values['DB_DATABASE'] ?? '');
        $username = (string) ($values['DB_USERNAME'] ?? '');
        if (
            ($values['DB_CONNECTION'] ?? 'mysql') !== 'mysql'
            || !str_starts_with($database, 'lp_')
            || !str_starts_with($username, 'lp_')
        ) {
            return null;
        }
        return ['database' => $database, 'username' => $username];
    }

    public function roots(): array
    {
        $configured = EnvironmentConfig::paths('document_roots');

        $roots = [];
        foreach ($configured as $root) {
            try {
                $path = PathGuard::assertAllowedDirectory((string) $root);
            } catch (\Throwable) {
                continue;
            }

            $roots[$path] = [
                'path' => $path,
                'writable' => is_writable($path),
                'url' => $this->urlForPath($path),
            ];
        }

        return array_values($roots);
    }

    public function sites(bool $includeSizes = true, ?array $roots = null): array
    {
        $roots = $roots ?? $this->roots();
        $managed = $this->managedSites();
        $byPath = [];
        foreach ($managed as $site) {
            if ($this->isExcludedSiteName(basename((string) $site['document_root']))) {
                continue;
            }
            $byPath[$site['document_root']] = $this->sitePayload($site + ['managed' => true], $includeSizes, $roots);
        }

        foreach ($roots as $root) {
            if (!is_readable($root['path'])) {
                continue;
            }

            foreach (scandir($root['path']) ?: [] as $name) {
                if ($name === '.' || $name === '..' || $name === basename(APP_ROOT) || $this->isExcludedSiteName($name)) {
                    continue;
                }

                $path = $root['path'] . DIRECTORY_SEPARATOR . $name;
                if (!is_dir($path) || isset($byPath[$path])) {
                    continue;
                }

                $byPath[$path] = $this->sitePayload([
                    'id' => null,
                    'name' => $name,
                    'slug' => $name,
                    'domain' => null,
                    'document_root' => $path,
                    'status' => 'discovered',
                    'php_version' => null,
                    'created_at' => null,
                    'updated_at' => null,
                    'managed' => false,
                ], $includeSizes, $roots);
            }
        }

        $sites = array_values($byPath);
        usort($sites, static fn (array $a, array $b): int => strtolower($a['name']) <=> strtolower($b['name']));

        return $sites;
    }

    public function resolveLaravelSitePath(string $documentRoot): string
    {
        $path = $this->resolveSiteManagePath($documentRoot);
        if (!$this->isRunnableLaravelProject($path)) {
            throw new \InvalidArgumentException('The selected site is not a complete Laravel project.');
        }

        return $path;
    }

    private function managedSites(): array
    {
        try {
            $statement = Database::pdo()->prepare(
                'SELECT * FROM hosting_sites
                 WHERE organization_id = ? AND machine_id = ? ORDER BY name'
            );
            $statement->execute([CommercialContext::organizationId(), CommercialContext::machineId()]);
            return $statement->fetchAll();
        } catch (\Throwable) {
            return [];
        }
    }

    private function sitePayload(array $site, bool $includeSize = true, ?array $roots = null): array
    {
        $path = (string) $site['document_root'];
        $laravel = is_file($path . '/routes/web.php') && is_dir($path . '/resources/views');

        return [
            'id' => $site['id'] ?? null,
            'name' => (string) $site['name'],
            'slug' => (string) $site['slug'],
            'domain' => (string) ($site['domain'] ?? ''),
            'document_root' => $path,
            'url' => $this->urlForPath($path),
            'status' => (string) ($site['status'] ?? 'active'),
            'php_version' => (string) ($site['php_version'] ?? ''),
            'managed' => !empty($site['managed']),
            'writable' => is_writable($path),
            'editable' => $this->isManageableSitePath($path, $roots),
            'deletable' => $this->isDeletableSitePath($path, $roots),
            'laravel' => $laravel,
            'artisan_ready' => $laravel && $this->isRunnableLaravelProject($path),
            'database_name' => $laravel ? $this->laravelDatabaseName($path) : '',
            'has_index' => is_file($path . '/index.php') || is_file($path . '/index.html'),
            'size_human' => $includeSize ? $this->folderSizeHuman($path) : '',
            'updated_at' => (string) ($site['updated_at'] ?? ''),
        ];
    }

    private function isRunnableLaravelProject(string $path): bool
    {
        foreach ([
            'artisan',
            'composer.json',
            'vendor/autoload.php',
            'bootstrap/app.php',
            'public/index.php',
            'routes/web.php',
        ] as $relativePath) {
            if (!is_file($path . DIRECTORY_SEPARATOR . $relativePath)) {
                return false;
            }
        }

        return is_dir($path . '/resources/views');
    }

    private function laravelDatabaseName(string $path): string
    {
        $envPath = $path . '/.env';
        if (!is_file($envPath) || !is_readable($envPath)) {
            return '';
        }

        $contents = file_get_contents($envPath, false, null, 0, 65536);
        if ($contents === false || !preg_match('/^DB_CONNECTION\s*=\s*["\']?mysql["\']?\s*$/m', $contents)) {
            return '';
        }
        if (!preg_match('/^DB_DATABASE\s*=\s*(.+?)\s*$/m', $contents, $match)) {
            return '';
        }

        $database = trim($match[1], " \t\n\r\0\x0B\"'");
        return preg_match('/^[A-Za-z0-9_]+$/', $database) ? $database : '';
    }

    private function findSiteByPath(string $path): ?array
    {
        foreach ($this->sites(false) as $site) {
            $candidate = realpath((string) $site['document_root']) ?: (string) $site['document_root'];
            if ($candidate === $path) {
                return $site;
            }
        }

        return null;
    }

    private function resolveSiteManagePath(string $documentRoot): string
    {
        $submittedPath = rtrim(trim($documentRoot), DIRECTORY_SEPARATOR);
        if ($submittedPath === '') {
            throw new \InvalidArgumentException('Select a site.');
        }
        if (is_link($submittedPath)) {
            throw new \InvalidArgumentException('Linked site folders cannot be managed from the hosting page.');
        }

        $path = PathGuard::assertAllowedDirectory($submittedPath);
        if (!$this->isManageableSitePath($path)) {
            throw new \InvalidArgumentException('This site cannot be managed from the hosting page.');
        }

        return $path;
    }

    private function isManageableSitePath(string $path, ?array $roots = null): bool
    {
        $submittedPath = rtrim($path, DIRECTORY_SEPARATOR);
        if ($submittedPath === '' || is_link($submittedPath) || $this->isExcludedSiteName(basename($submittedPath))) {
            return false;
        }

        $real = realpath($submittedPath);
        $appRoot = realpath(APP_ROOT) ?: APP_ROOT;
        if (!$real || !is_dir($real)) {
            return false;
        }
        if ($real === $appRoot || str_starts_with($real, $appRoot . DIRECTORY_SEPARATOR) || str_starts_with($appRoot, $real . DIRECTORY_SEPARATOR)) {
            return false;
        }

        foreach (($roots ?? $this->roots()) as $root) {
            $rootPath = realpath((string) ($root['path'] ?? ''));
            if ($rootPath && dirname($real) === $rootPath) {
                return true;
            }
        }

        return false;
    }

    private function isDeletableSitePath(string $path, ?array $roots = null): bool
    {
        return $this->isManageableSitePath($path, $roots);
    }

    private function isSiteDestinationPath(string $path, ?array $roots = null): bool
    {
        $parent = realpath(dirname($path));
        $basename = basename($path);
        if (!$parent || $basename === '' || $this->isExcludedSiteName($basename)) {
            return false;
        }

        $target = $parent . DIRECTORY_SEPARATOR . $basename;
        $appRoot = realpath(APP_ROOT) ?: APP_ROOT;
        if ($target === $appRoot || str_starts_with($target, $appRoot . DIRECTORY_SEPARATOR) || str_starts_with($appRoot, $target . DIRECTORY_SEPARATOR)) {
            return false;
        }

        foreach (($roots ?? $this->roots()) as $root) {
            $rootPath = realpath((string) ($root['path'] ?? ''));
            if ($rootPath && $parent === $rootPath) {
                return true;
            }
        }

        return false;
    }

    private function isHiddenSiteName(string $name): bool
    {
        return $name === '' || str_starts_with($name, '.');
    }

    private function isExcludedSiteName(string $name): bool
    {
        return $this->isHiddenSiteName($name) || str_starts_with($name, 'admin_terminal_opened_');
    }

    private function assertManagedSiteMetadataAvailable(array $site, string $slug, string $domain, string $targetPath): void
    {
        $id = $site['id'] !== null ? (int) $site['id'] : null;
        $excludeSql = $id !== null ? 'id <> ?' : 'document_root <> ?';
        $excludeValue = $id !== null ? $id : (string) $site['document_root'];
        $pdo = Database::pdo();

        $stmt = $pdo->prepare(
            "SELECT name FROM hosting_sites
             WHERE organization_id = ? AND machine_id = ? AND slug = ? AND {$excludeSql} LIMIT 1"
        );
        $stmt->execute([CommercialContext::organizationId(), CommercialContext::machineId(), $slug, $excludeValue]);
        if ($stmt->fetch()) {
            throw new \InvalidArgumentException('Another managed site already uses that name.');
        }

        if ($domain !== '') {
            $stmt = $pdo->prepare(
                "SELECT name FROM hosting_sites
                 WHERE organization_id = ? AND machine_id = ? AND domain = ? AND {$excludeSql} LIMIT 1"
            );
            $stmt->execute([CommercialContext::organizationId(), CommercialContext::machineId(), $domain, $excludeValue]);
            if ($stmt->fetch()) {
                throw new \InvalidArgumentException('Another managed site already uses that domain.');
            }
        }

        $stmt = $pdo->prepare(
            "SELECT name FROM hosting_sites
             WHERE organization_id = ? AND machine_id = ? AND document_root = ? AND {$excludeSql} LIMIT 1"
        );
        $stmt->execute([CommercialContext::organizationId(), CommercialContext::machineId(), $targetPath, $excludeValue]);
        if ($stmt->fetch()) {
            throw new \InvalidArgumentException('Another managed site already uses that document root.');
        }
    }

    private function removeManagedSite(string $path, ?int $id = null): int
    {
        try {
            if ($id !== null) {
                $stmt = Database::pdo()->prepare(
                    'DELETE FROM hosting_sites
                     WHERE (id = ? OR document_root = ?) AND organization_id = ? AND machine_id = ?'
                );
                $stmt->execute([
                    $id,
                    $path,
                    CommercialContext::organizationId(),
                    CommercialContext::machineId(),
                ]);
                return $stmt->rowCount();
            }

            $stmt = Database::pdo()->prepare(
                'DELETE FROM hosting_sites
                 WHERE document_root = ? AND organization_id = ? AND machine_id = ?'
            );
            $stmt->execute([$path, CommercialContext::organizationId(), CommercialContext::machineId()]);
            return $stmt->rowCount();
        } catch (\Throwable $exception) {
            AuditLogger::error('hosting.site.delete_record_failed', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);

            return 0;
        }
    }

    private function resolveRoot(string $root): string
    {
        $roots = $this->roots();
        if (!$roots) {
            throw new \RuntimeException('No website roots are configured.');
        }

        if ($root === '') {
            return $roots[0]['path'];
        }

        $root = PathGuard::assertAllowedDirectory($root);
        foreach ($roots as $candidate) {
            if ($candidate['path'] === $root) {
                return $root;
            }
        }

        throw new \InvalidArgumentException('Selected website root is not configured for hosting.');
    }

    private function slug(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-_');
        if ($slug === '' || !preg_match('/\A[a-z0-9][a-z0-9_-]{0,62}\z/', $slug)) {
            throw new \InvalidArgumentException('Use a site name with letters, numbers, dashes, or underscores.');
        }

        return $slug;
    }

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return '';
        }

        if (!preg_match('/\A[a-z0-9.-]+\z/', $domain)) {
            throw new \InvalidArgumentException('Domain can only contain letters, numbers, dots, and dashes.');
        }

        return $domain;
    }

    private function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return match ($status) {
            'active', 'paused', 'archived' => $status,
            default => throw new \InvalidArgumentException('Status must be active, paused, or archived.'),
        };
    }

    private function urlForPath(string $path): string
    {
        $htdocs = realpath(EnvironmentConfig::paths('document_roots')[0] ?? dirname(APP_ROOT));
        $real = realpath($path);
        if (!$real) {
            $parent = realpath(dirname($path));
            if ($parent) {
                $real = $parent . DIRECTORY_SEPARATOR . basename($path);
            }
        }
        if ($htdocs && $real && ($real === $htdocs || str_starts_with($real, $htdocs . DIRECTORY_SEPARATOR))) {
            $relative = trim(substr($real, strlen($htdocs)), DIRECTORY_SEPARATOR);
            if ($relative === '') {
                return 'http://localhost/';
            }

            return 'http://localhost/' . str_replace('%2F', '/', rawurlencode(str_replace(DIRECTORY_SEPARATOR, '/', $relative))) . '/';
        }

        return '';
    }

    private function rollbackCreatedSite(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }

        try {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($iterator as $item) {
                if ($item->isLink() || $item->isFile()) {
                    @unlink($item->getPathname());
                } else {
                    @rmdir($item->getPathname());
                }
            }
            @rmdir($path);
        } catch (\Throwable $exception) {
            AuditLogger::error('hosting.site.rollback_failed', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function folderSizeHuman(string $path): string
    {
        if (!is_dir($path)) {
            return 'Unavailable';
        }

        $cacheKey = hash('sha256', $path);
        $cache = $this->siteSizeCache();
        $cached = $cache[$cacheKey] ?? null;
        if (
            is_array($cached)
            && ($cached['path'] ?? '') === $path
            && time() - (int) ($cached['created_at'] ?? 0) <= self::SITE_SIZE_CACHE_TTL
        ) {
            return (string) ($cached['size_human'] ?? 'Unavailable');
        }

        $bytes = 0;
        $count = 0;
        $size = 'Unavailable';
        try {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($count++ > 2000) {
                    $size = human_bytes($bytes) . '+';
                    break;
                }
                if ($file->isFile()) {
                    $bytes += $file->getSize();
                }
            }
            if ($size === 'Unavailable') {
                $size = human_bytes($bytes);
            }
        } catch (\Throwable) {
            return 'Unavailable';
        }

        $this->siteSizeCache[$cacheKey] = [
            'path' => $path,
            'size_human' => $size,
            'created_at' => time(),
        ];
        $this->writeSiteSizeCache();
        return $size;
    }

    private function siteSizeCache(): array
    {
        if ($this->siteSizeCache !== null) {
            return $this->siteSizeCache;
        }

        $path = STORAGE_PATH . '/cache/hosting-sizes.json';
        $cache = is_readable($path) ? json_decode((string) @file_get_contents($path), true) : [];
        $this->siteSizeCache = is_array($cache) ? $cache : [];
        return $this->siteSizeCache;
    }

    private function writeSiteSizeCache(): void
    {
        $json = json_encode($this->siteSizeCache ?? [], JSON_UNESCAPED_SLASHES);
        if ($json !== false) {
            @file_put_contents(STORAGE_PATH . '/cache/hosting-sizes.json', $json, LOCK_EX);
        }
    }
}
