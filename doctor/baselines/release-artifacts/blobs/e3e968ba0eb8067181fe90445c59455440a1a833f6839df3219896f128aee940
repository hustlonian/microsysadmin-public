<?php

declare(strict_types=1);

namespace SysAdmin;

use Symfony\Component\Process\Process;

final class LaravelSiteProvisioner
{
    private string $phpBinary;
    private string $composerBinary;
    private string $laravelVersion;
    private int $timeout;

    public function __construct(
        ?string $phpBinary = null,
        ?string $composerBinary = null,
        ?string $laravelVersion = null,
        ?int $timeout = null
    ) {
        $this->phpBinary = $phpBinary ?? EnvironmentConfig::path('php_binary');
        $this->composerBinary = $composerBinary ?? EnvironmentConfig::path('composer_binary');
        $this->laravelVersion = $laravelVersion ?? (string) config_value('hosting.laravel_version', '^12.0');
        $this->timeout = max(60, min(1800, $timeout ?? (int) config_value('hosting.provision_timeout', 600)));
    }

    public function provision(string $targetPath, string $appName, string $appUrl, array $database): void
    {
        $parent = realpath(dirname($targetPath));
        if (!$parent || !is_dir($parent) || !is_writable($parent)) {
            throw new \RuntimeException('The website root is not available for Laravel provisioning.');
        }

        $targetPath = $parent . DIRECTORY_SEPARATOR . basename($targetPath);
        if (file_exists($targetPath) || is_link($targetPath)) {
            throw new \InvalidArgumentException('A site folder with this name already exists.');
        }

        $this->assertExecutable($this->phpBinary, 'XAMPP PHP');
        $this->assertExecutable($this->composerBinary, 'Composer');

        $lockPath = $parent . DIRECTORY_SEPARATOR . '.microsysadmin-site-create.lock';
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new \RuntimeException('Unable to lock the website root for site creation.');
        }
        @chmod($lockPath, 0660);

        $stagingPath = '';
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Unable to lock the website root for site creation.');
            }
            if (file_exists($targetPath) || is_link($targetPath)) {
                throw new \InvalidArgumentException('A site folder with this name already exists.');
            }

            $stagingPath = $parent . DIRECTORY_SEPARATOR . '.' . basename($targetPath)
                . '.creating-' . bin2hex(random_bytes(6));

            $this->createLaravelProject($parent, $stagingPath);
            $this->configureProject($stagingPath, $targetPath, $appName, $appUrl, $database);

            if (!rename($stagingPath, $targetPath)) {
                throw new \RuntimeException('Unable to publish the completed Laravel site.');
            }
            $stagingPath = '';
        } catch (\Throwable $exception) {
            if ($stagingPath !== '') {
                $this->removeTree($stagingPath);
            }
            throw $exception;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function createLaravelProject(string $parent, string $stagingPath): void
    {
        $composerRoot = STORAGE_PATH . '/composer';
        $this->ensureDirectory($composerRoot);
        @chmod($composerRoot, 02770);
        $runtimeUser = function_exists('posix_geteuid') ? (string) posix_geteuid() : PHP_SAPI;
        $composerHome = $composerRoot . '/user-' . preg_replace('/[^A-Za-z0-9_-]/', '_', $runtimeUser);
        $this->ensureDirectory($composerHome);
        @chmod($composerHome, 02770);
        @set_time_limit($this->timeout + 30);

        $command = [
            $this->phpBinary,
            $this->composerBinary,
            'create-project',
            '--prefer-dist',
            '--no-interaction',
            '--no-progress',
            '--no-ansi',
            'laravel/laravel',
            $stagingPath,
            $this->laravelVersion,
        ];

        $process = new Process($command, $parent, [
            'COMPOSER_ALLOW_SUPERUSER' => '1',
            'COMPOSER_HOME' => $composerHome,
            'COMPOSER_NO_INTERACTION' => '1',
        ]);
        $process->setTimeout($this->timeout);
        $previousUmask = umask(0002);
        try {
            $process->run();
        } finally {
            umask($previousUmask);
        }

        if ($process->isSuccessful()) {
            return;
        }

        $details = trim($process->getErrorOutput() . "\n" . $process->getOutput());
        $details = preg_replace('/\x1B\[[0-9;]*[A-Za-z]/', '', $details) ?? $details;
        if (strlen($details) > 1800) {
            $details = substr($details, -1800);
        }

        throw new \RuntimeException(
            'Composer could not create the Laravel project'
            . ($details !== '' ? ': ' . $details : '.')
        );
    }

    private function configureProject(
        string $projectPath,
        string $targetPath,
        string $appName,
        string $appUrl,
        array $database
    ): void {
        $this->assertLaravelProject($projectPath);
        $this->assertDatabaseConfiguration($database);

        $envExamplePath = $projectPath . '/.env.example';
        $envTemplate = (string) file_get_contents($envExamplePath);
        if ($envTemplate === '') {
            throw new \RuntimeException('The Laravel environment template is missing.');
        }

        $appName = preg_replace('/\s+/', ' ', trim($appName)) ?? trim($appName);
        $appUrl = rtrim(trim($appUrl), '/');
        $appKey = 'base64:' . base64_encode(random_bytes(32));

        $env = $this->configureEnvironment($envTemplate, [
            'APP_NAME' => $this->quoteEnvironmentValue($appName),
            'APP_KEY' => $appKey,
            'APP_URL' => $this->quoteEnvironmentValue($appUrl),
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $this->quoteEnvironmentValue((string) $database['host']),
            'DB_PORT' => (string) (int) $database['port'],
            'DB_DATABASE' => $this->quoteEnvironmentValue((string) $database['database']),
            'DB_USERNAME' => $this->quoteEnvironmentValue((string) $database['username']),
            'DB_PASSWORD' => $this->quoteEnvironmentValue((string) $database['password']),
            'SESSION_DRIVER' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'file',
        ]);
        $envExample = $this->configureEnvironment($envTemplate, [
            'APP_NAME' => $this->quoteEnvironmentValue($appName),
            'APP_KEY' => '',
            'APP_URL' => $this->quoteEnvironmentValue($appUrl),
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $this->quoteEnvironmentValue((string) $database['host']),
            'DB_PORT' => (string) (int) $database['port'],
            'DB_DATABASE' => $this->quoteEnvironmentValue((string) $database['database']),
            'DB_USERNAME' => $this->quoteEnvironmentValue((string) $database['username']),
            'DB_PASSWORD' => '',
            'SESSION_DRIVER' => 'file',
            'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'file',
        ]);

        $this->writeFile($projectPath . '/.env', $env, 0660);
        $this->writeFile($envExamplePath, $envExample, 0664);
        $this->writeFile($projectPath . '/index.php', $this->rootFrontController(), 0664);
        $this->writeFile($projectPath . '/.htaccess', $this->rootHtaccess($targetPath), 0664);
        $this->writeFile(
            $projectPath . '/public/.htaccess',
            $this->publicHtaccess($appUrl),
            0664
        );

        $sqlitePath = $projectPath . '/database/database.sqlite';
        if (is_file($sqlitePath) && !unlink($sqlitePath)) {
            throw new \RuntimeException('Unable to remove the unused Laravel SQLite database.');
        }

        foreach ([
            'bootstrap/cache',
            'storage',
            'storage/app',
            'storage/app/private',
            'storage/app/public',
            'storage/framework',
            'storage/framework/cache',
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/testing',
            'storage/framework/views',
            'storage/logs',
        ] as $relativePath) {
            $this->ensureDirectory($projectPath . DIRECTORY_SEPARATOR . $relativePath);
            @chmod($projectPath . DIRECTORY_SEPARATOR . $relativePath, 02775);
        }

        foreach ([
            'artisan' => 0775,
            'routes/web.php' => 0664,
            'resources/views/welcome.blade.php' => 0664,
        ] as $relativePath => $mode) {
            @chmod($projectPath . DIRECTORY_SEPARATOR . $relativePath, $mode);
        }
        @chmod($projectPath, 02775);

        $this->runMigrations($projectPath);
    }

    private function assertDatabaseConfiguration(array $database): void
    {
        foreach (['database', 'username', 'password', 'host', 'port'] as $key) {
            if (!array_key_exists($key, $database) || (string) $database[$key] === '') {
                throw new \RuntimeException("Laravel MySQL configuration is missing {$key}.");
            }
        }
        if (
            !preg_match('/^[A-Za-z0-9_]+$/', (string) $database['database'])
            || !preg_match('/^[A-Za-z0-9_]+$/', (string) $database['username'])
        ) {
            throw new \RuntimeException('Laravel MySQL identifiers are invalid.');
        }
    }

    private function runMigrations(string $projectPath): void
    {
        $process = new Process([
            $this->phpBinary,
            'artisan',
            'migrate',
            '--force',
            '--no-interaction',
            '--no-ansi',
        ], $projectPath);
        $process->setTimeout(min($this->timeout, 300));
        $process->run();

        if ($process->isSuccessful()) {
            return;
        }

        $details = trim($process->getErrorOutput() . "\n" . $process->getOutput());
        if (strlen($details) > 1600) {
            $details = substr($details, -1600);
        }
        throw new \RuntimeException(
            'Laravel could not migrate its MySQL database'
            . ($details !== '' ? ': ' . $details : '.')
        );
    }

    private function assertLaravelProject(string $projectPath): void
    {
        foreach ([
            'artisan',
            'composer.json',
            'vendor/autoload.php',
            'bootstrap/app.php',
            'public/index.php',
            'routes/web.php',
            'resources/views/welcome.blade.php',
        ] as $relativePath) {
            if (!is_file($projectPath . DIRECTORY_SEPARATOR . $relativePath)) {
                throw new \RuntimeException("Laravel provisioning did not create {$relativePath}.");
            }
        }
    }

    private function configureEnvironment(string $contents, array $values): string
    {
        $contents = str_replace(["\r\n", "\r"], "\n", $contents);
        foreach ($values as $key => $value) {
            $pattern = '/^(?:#\s*)?' . preg_quote((string) $key, '/') . '=.*$/m';
            $replacement = (string) $key . '=' . (string) $value;
            if (preg_match($pattern, $contents) === 1) {
                $contents = preg_replace_callback(
                    $pattern,
                    static fn (): string => $replacement,
                    $contents
                ) ?? $contents;
            } else {
                $contents = rtrim($contents) . "\n" . $replacement . "\n";
            }
        }

        return rtrim($contents) . "\n";
    }

    private function quoteEnvironmentValue(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }

    private function rootFrontController(): string
    {
        return <<<'PHP'
<?php

declare(strict_types=1);

require __DIR__ . '/public/index.php';
PHP;
    }

    private function rootHtaccess(string $targetPath): string
    {
        $publicPath = str_replace(['\\', '"'], ['/', '\\"'], $targetPath . '/public');

        return <<<APACHE
<IfModule mod_rewrite.c>
    RewriteEngine On

    RewriteCond %{REQUEST_FILENAME} -f [OR]
    RewriteCond %{REQUEST_FILENAME} -d
    RewriteRule ^ - [L]

    RewriteCond "{$publicPath}/\$1" -f [OR]
    RewriteCond "{$publicPath}/\$1" -d
    RewriteRule ^(.+)$ public/\$1 [L]

    RewriteRule ^ index.php [L]
</IfModule>
APACHE;
    }

    private function publicHtaccess(string $appUrl): string
    {
        $basePath = (string) parse_url($appUrl, PHP_URL_PATH);
        $basePath = '/' . trim($basePath, '/') . '/';

        return <<<APACHE
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On
    RewriteBase {$basePath}

    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    RewriteCond %{HTTP:X-XSRF-TOKEN} .
    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-TOKEN}]

    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>
APACHE;
    }

    private function assertExecutable(string $path, string $label): void
    {
        if (!is_file($path) || !is_executable($path)) {
            throw new \RuntimeException("{$label} is not available at {$path}.");
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 02775, true) && !is_dir($path)) {
            throw new \RuntimeException("Unable to create directory {$path}.");
        }
    }

    private function writeFile(string $path, string $contents, int $mode): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("Unable to write {$path}.");
        }
        @chmod($path, $mode);
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
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
        } catch (\Throwable) {
        }
        @rmdir($path);
    }
}
