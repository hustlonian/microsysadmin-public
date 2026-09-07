<?php

declare(strict_types=1);

namespace SysAdmin;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use ZipArchive;

final class FileVault implements \SysAdmin\Contracts\FileOperationsInterface
{
    private ?array $websiteRootPaths = null;

    public function __construct(private readonly bool $allowSitePaths = false)
    {
    }

    public function roots(): array
    {
        return PathGuard::allowedRoots();
    }

    public function list(string $path = ''): array
    {
        $dir = $path !== '' ? $this->assertVisibleDirectory($path) : $this->assertVisibleDirectory(PathGuard::defaultRoot());
        $entries = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $full = $dir . DIRECTORY_SEPARATOR . $name;
            if (PathGuard::isReservedMetadataName($name) || $this->isReservedMetadataPath($full) || $this->isSitePath($full, is_dir($full))) {
                continue;
            }

            $stat = @stat($full);
            $entries[] = [
                'name' => $name,
                'path' => $full,
                'type' => is_dir($full) ? 'folder' : 'file',
                'size' => is_file($full) ? (int) filesize($full) : 0,
                'size_human' => is_file($full) ? human_bytes((int) filesize($full)) : '',
                'modified' => $stat ? date('Y-m-d H:i:s', $stat['mtime']) : '',
                'permissions' => substr(sprintf('%o', @fileperms($full)), -4),
                'owner' => function_exists('posix_getpwuid') && $stat ? (posix_getpwuid($stat['uid'])['name'] ?? (string) $stat['uid']) : '',
                'group' => function_exists('posix_getgrgid') && $stat ? (posix_getgrgid($stat['gid'])['name'] ?? (string) $stat['gid']) : '',
            ];
        }

        usort($entries, static fn (array $a, array $b): int => [$a['type'] !== 'folder', strtolower($a['name'])] <=> [$b['type'] !== 'folder', strtolower($b['name'])]);

        return [
            'path' => $dir,
            'parent' => dirname($dir),
            'roots' => $this->roots(),
            'entries' => $entries,
            'breadcrumbs' => $this->breadcrumbs($dir),
        ];
    }

    public function search(string $root, string $query, int $limit = 100): array
    {
        $root = $this->assertVisibleDirectory($root ?: PathGuard::defaultRoot());
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $results = [];
        $directory = new RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS);
        $filter = new \RecursiveCallbackFilterIterator($directory, fn (\SplFileInfo $file): bool => !$this->isReservedMetadataPath($file->getPathname()) && !$this->isSitePath($file->getPathname(), $file->isDir()));
        $iterator = new RecursiveIteratorIterator($filter);
        foreach ($iterator as $file) {
            if (count($results) >= $limit) {
                break;
            }
            if (stripos($file->getFilename(), $query) !== false) {
                $path = $file->getPathname();
                $results[] = [
                    'name' => $file->getFilename(),
                    'path' => $path,
                    'type' => $file->isDir() ? 'folder' : 'file',
                    'size_human' => $file->isFile() ? human_bytes($file->getSize()) : '',
                    'modified' => date('Y-m-d H:i:s', $file->getMTime()),
                ];
            }
        }

        return $results;
    }

    public function preview(string $path): array
    {
        $path = $this->assertVisiblePath($path);
        if (is_dir($path)) {
            return ['type' => 'folder', 'content' => 'Folder with ' . count($this->visibleEntries($path)) . ' entries'];
        }

        $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream';
        if (str_starts_with($mime, 'image/')) {
            return ['type' => 'image', 'mime' => $mime, 'url' => url('api/file.php?action=download&inline=1&path=' . rawurlencode($path))];
        }

        if ($this->isTextFile($path)) {
            return ['type' => 'text', 'mime' => $mime, 'content' => $this->readPreviewContent($path)];
        }

        return ['type' => 'binary', 'mime' => $mime, 'content' => 'Binary preview is not available.'];
    }

    public function read(string $path): array
    {
        $path = $this->assertVisiblePath($path, false);
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Select a file to edit.');
        }
        if (!is_readable($path)) {
            return $this->runPrivileged('read', ['path' => $path]);
        }
        if (!$this->isTextFile($path)) {
            throw new \InvalidArgumentException('Only text files can be edited.');
        }

        $content = $this->readEditableContent($path);
        $stat = @stat($path);

        return [
            'path' => $path,
            'name' => basename($path),
            'mime' => function_exists('mime_content_type') ? (mime_content_type($path) ?: 'text/plain') : 'text/plain',
            'size' => is_file($path) ? (int) filesize($path) : 0,
            'modified' => $stat ? date('Y-m-d H:i:s', $stat['mtime']) : '',
            'content' => $content,
        ];
    }

    public function saveFile(string $path, string $content): string
    {
        $path = $this->assertVisiblePath($path, false);
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Select a file to edit.');
        }

        if (!is_readable($path)) {
            $result = $this->runPrivileged('save-file', ['path' => $path, 'content' => $content]);
            AuditLogger::info('file.save', ['path' => $result['path'] ?? $path]);
            return (string) ($result['path'] ?? $path);
        }
        if (!$this->isTextFile($path)) {
            throw new \InvalidArgumentException('Only text files can be edited.');
        }

        $maxBytes = (int) config_value('security.max_file_edit_bytes', 1048576);
        if (strlen($content) > $maxBytes) {
            throw new \RuntimeException('File content is too large to save from the browser.');
        }

        if (!is_writable($path) || @file_put_contents($path, $content, LOCK_EX) === false) {
            $result = $this->runPrivileged('save-file', ['path' => $path, 'content' => $content]);
            AuditLogger::info('file.save', ['path' => $result['path'] ?? $path]);
            return (string) ($result['path'] ?? $path);
        }

        AuditLogger::info('file.save', ['path' => $path]);
        return $path;
    }

    public function upload(string $directory, array $upload): array
    {
        $directory = $this->assertVisibleDirectory($directory);
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new \RuntimeException('Upload failed.');
        }

        $sizeMb = ((int) $upload['size']) / 1048576;
        if ($sizeMb > (int) config_value('security.max_upload_mb', 128)) {
            throw new \RuntimeException('Upload exceeds configured size limit.');
        }

        $name = PathGuard::safeName((string) $upload['name']);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext && !in_array($ext, (array) config_value('security.allowed_upload_extensions', []), true)) {
            throw new \RuntimeException('File type is not allowed.');
        }

        $target = $directory . DIRECTORY_SEPARATOR . $name;
        $this->assertVisiblePath($target, false);
        if (file_exists($target)) {
            throw new \RuntimeException('A file or folder with that name already exists.');
        }
        if (is_writable($directory) && @move_uploaded_file((string) $upload['tmp_name'], $target)) {
            AuditLogger::info('file.upload', ['path' => $target]);
            return ['path' => $target, 'name' => $name];
        }

        if (!$this->canUsePrivilegedHelper()) {
            throw new \RuntimeException('Unable to move uploaded file.');
        }
        $staged = $this->stageUpload((string) $upload['tmp_name'], $name);
        try {
            $result = $this->runPrivileged('upload', ['source' => $staged, 'directory' => $directory, 'name' => $name]);
        } catch (\Throwable $exception) {
            @unlink($staged);
            throw $exception;
        }
        AuditLogger::info('file.upload', ['path' => $result['path'] ?? $target]);
        return ['path' => (string) ($result['path'] ?? $target), 'name' => $name];
    }

    public function installStagedUpload(string $source, string $directory, string $name): string
    {
        $source = PathGuard::assertAllowed($source);
        if (!is_file($source)) {
            throw new \InvalidArgumentException('Staged upload is not a file.');
        }

        $target = $this->assertVisibleDirectory($directory) . DIRECTORY_SEPARATOR . PathGuard::safeName($name);
        $this->assertVisiblePath($target, false);
        if (file_exists($target)) {
            throw new \RuntimeException('A file or folder with that name already exists.');
        }

        if (!@rename($source, $target)) {
            if (!@copy($source, $target)) {
                throw new \RuntimeException('Unable to install staged upload.');
            }
            if (!@unlink($source)) {
                throw new \RuntimeException('Unable to remove staged upload.');
            }
        }
        if (!file_exists($target)) {
            throw new \RuntimeException('Unable to move uploaded file.');
        }

        AuditLogger::info('file.upload', ['path' => $target]);
        return $target;
    }

    public function createFolder(string $directory, string $name): string
    {
        $path = $this->assertVisibleDirectory($directory) . DIRECTORY_SEPARATOR . PathGuard::safeName($name);
        $this->assertVisiblePath($path, true);
        if (file_exists($path)) {
            throw new \RuntimeException('A file or folder with that name already exists.');
        }
        if (!@mkdir($path, 0775, false) && !is_dir($path)) {
            $result = $this->runPrivileged('create-folder', ['path' => $directory, 'name' => $name]);
            AuditLogger::info('file.mkdir', ['path' => $result['path'] ?? $path]);
            return (string) ($result['path'] ?? $path);
        }
        AuditLogger::info('file.mkdir', ['path' => $path]);
        return $path;
    }

    public function createFile(string $directory, string $name, string $content = ''): string
    {
        $path = $this->assertVisibleDirectory($directory) . DIRECTORY_SEPARATOR . PathGuard::safeName($name);
        $this->assertVisiblePath($path, false);
        if (file_exists($path)) {
            throw new \RuntimeException('A file or folder with that name already exists.');
        }
        if (@file_put_contents($path, $content, LOCK_EX) === false) {
            $result = $this->runPrivileged('create-file', ['path' => $directory, 'name' => $name, 'content' => $content]);
            AuditLogger::info('file.create', ['path' => $result['path'] ?? $path]);
            return (string) ($result['path'] ?? $path);
        }
        AuditLogger::info('file.create', ['path' => $path]);
        return $path;
    }

    public function rename(string $path, string $name): string
    {
        $path = $this->assertVisiblePath($path);
        $target = dirname($path) . DIRECTORY_SEPARATOR . PathGuard::safeName($name);
        $this->assertVisiblePath($target, is_dir($path));
        if (file_exists($target)) {
            throw new \RuntimeException('A file or folder with that name already exists.');
        }
        if (!@rename($path, $target)) {
            $result = $this->runPrivileged('rename', ['path' => $path, 'name' => $name]);
            AuditLogger::info('file.rename', ['from' => $path, 'to' => $result['path'] ?? $target]);
            return (string) ($result['path'] ?? $target);
        }
        AuditLogger::info('file.rename', ['from' => $path, 'to' => $target]);
        return $target;
    }

    public function copy(string $path, string $directory): string
    {
        $path = $this->assertVisiblePath($path);
        $directory = $this->assertVisibleDirectory($directory);
        $target = $directory . DIRECTORY_SEPARATOR . basename($path);
        $this->assertVisiblePath($target, is_dir($path));
        if (file_exists($target)) {
            throw new \RuntimeException('A file or folder with that name already exists in the target directory.');
        }
        if (is_dir($path) && str_starts_with($target, $path . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Cannot copy a folder into itself.');
        }
        try {
            $this->copyRecursive($path, $target);
        } catch (\RuntimeException $exception) {
            $result = $this->runPrivileged('copy', ['path' => $path, 'target' => $directory]);
            AuditLogger::info('file.copy', ['from' => $path, 'to' => $result['path'] ?? $target]);
            return (string) ($result['path'] ?? $target);
        }
        AuditLogger::info('file.copy', ['from' => $path, 'to' => $target]);
        return $target;
    }

    public function move(string $path, string $directory): string
    {
        $path = $this->assertVisiblePath($path);
        $directory = $this->assertVisibleDirectory($directory);
        $target = $directory . DIRECTORY_SEPARATOR . basename($path);
        $this->assertVisiblePath($target, is_dir($path));
        if (file_exists($target)) {
            throw new \RuntimeException('A file or folder with that name already exists in the target directory.');
        }
        if (is_dir($path) && str_starts_with($target, $path . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Cannot move a folder into itself.');
        }
        if (!@rename($path, $target)) {
            if ($this->canUsePrivilegedHelper()) {
                $result = $this->runPrivileged('move', ['path' => $path, 'target' => $directory]);
                AuditLogger::info('file.move', ['from' => $path, 'to' => $result['path'] ?? $target]);
                return (string) ($result['path'] ?? $target);
            }
            $this->copyRecursive($path, $target);
            $this->removeRecursive($path);
        }
        if (!file_exists($target)) {
            throw new \RuntimeException('Unable to move path.');
        }
        AuditLogger::info('file.move', ['from' => $path, 'to' => $target]);
        return $target;
    }

    public function delete(string $path): string
    {
        $path = $this->assertVisiblePath($path);
        $trash = $this->trashPath($path);
        if (!@rename($path, $trash)) {
            if ($this->canUsePrivilegedHelper()) {
                $result = $this->runPrivileged('delete', ['path' => $path]);
                AuditLogger::warning('file.delete.trash', ['from' => $path, 'trash' => $result['trash'] ?? $trash]);
                return (string) ($result['trash'] ?? $trash);
            }
            try {
                $this->copyRecursive($path, $trash);
                $this->removeRecursive($path);
            } catch (\RuntimeException $exception) {
                throw new \RuntimeException('Unable to move path to trash.', 0, $exception);
            }
        }
        if (!file_exists($trash)) {
            throw new \RuntimeException('Unable to move path to trash.');
        }
        AuditLogger::warning('file.delete.trash', ['from' => $path, 'trash' => $trash]);
        return $trash;
    }

    public function zip(string $path): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('PHP ZipArchive extension is not installed.');
        }
        $path = $this->assertVisiblePath($path);
        $zipPath = (is_dir($path) ? dirname($path) : dirname($path)) . DIRECTORY_SEPARATOR . basename($path) . '-' . date('Ymd-His') . '.zip';
        $this->assertVisiblePath($zipPath, false);

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            $result = $this->runPrivileged('zip', ['path' => $path]);
            AuditLogger::info('file.zip', ['path' => $path, 'zip' => $result['archive'] ?? $zipPath]);
            return (string) ($result['archive'] ?? $zipPath);
        }
        $this->addToZip($zip, $path, basename($path));
        $zip->close();
        AuditLogger::info('file.zip', ['path' => $path, 'zip' => $zipPath]);
        return $zipPath;
    }

    public function unzip(string $path, string $directory): string
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('PHP ZipArchive extension is not installed.');
        }
        $path = $this->assertVisiblePath($path, false);
        $directory = $this->assertVisibleDirectory($directory);
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Unable to open archive.');
        }
        $target = $directory . DIRECTORY_SEPARATOR . pathinfo($path, PATHINFO_FILENAME);
        $this->assertVisiblePath($target, true);
        if (!is_dir($target) && !@mkdir($target, 0775, true) && !is_dir($target)) {
            $result = $this->runPrivileged('unzip', ['path' => $path, 'target' => $directory]);
            AuditLogger::info('file.unzip', ['path' => $path, 'target' => $result['target'] ?? $target]);
            return (string) ($result['target'] ?? $target);
        }
        if (!$this->extractZipSafely($zip, $target)) {
            $zip->close();
            $result = $this->runPrivileged('unzip', ['path' => $path, 'target' => $directory]);
            AuditLogger::info('file.unzip', ['path' => $path, 'target' => $result['target'] ?? $target]);
            return (string) ($result['target'] ?? $target);
        }
        $zip->close();
        AuditLogger::info('file.unzip', ['path' => $path, 'target' => $target]);
        return $target;
    }

    public function changePermissions(string $path, string $mode): array
    {
        $path = $this->assertVisiblePath($path);
        $mode = trim($mode);
        if (!preg_match('/^0?[0-7]{3,4}$/', $mode)) {
            throw new \InvalidArgumentException('Permissions must be 3 or 4 octal digits, like 644 or 0755.');
        }

        $numericMode = octdec($mode);
        if (!@chmod($path, $numericMode)) {
            if ($this->canUsePrivilegedHelper()) {
                $result = $this->runPrivileged('chmod', ['path' => $path, 'mode' => $mode]);
                AuditLogger::warning('file.permissions', ['path' => $result['path'] ?? $path, 'mode' => $result['permissions'] ?? $mode]);
                return [
                    'path' => (string) ($result['path'] ?? $path),
                    'permissions' => (string) ($result['permissions'] ?? $mode),
                ];
            }

            throw new \RuntimeException('Unable to change permissions.');
        }

        clearstatcache(true, $path);
        $permissions = substr(sprintf('%o', @fileperms($path)), -4);
        AuditLogger::warning('file.permissions', ['path' => $path, 'mode' => $permissions]);

        return ['path' => $path, 'permissions' => $permissions];
    }

    public function sendDownload(string $path, bool $inline = false): never
    {
        $path = $this->assertVisiblePath($path, false);
        if (!is_file($path) || !is_readable($path)) {
            http_response_code(404);
            exit('File not found');
        }
        $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: 'application/octet-stream') : 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header(($inline ? 'Content-Disposition: inline; filename="' : 'Content-Disposition: attachment; filename="') . addslashes(basename($path)) . '"');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    private function assertVisiblePath(string $path, ?bool $targetIsDirectory = null): string
    {
        $path = PathGuard::assertAllowed($path);
        if ($this->isReservedMetadataPath($path)) {
            throw new \InvalidArgumentException('.agents and .git are reserved metadata folders and cannot be managed here.');
        }
        if ($this->isSitePath($path, $targetIsDirectory)) {
            throw new \InvalidArgumentException('Site folders and files are managed from the Sites section.');
        }

        return $path;
    }

    private function assertVisibleDirectory(string $path): string
    {
        $path = PathGuard::assertAllowedDirectory($path);
        if ($this->isReservedMetadataPath($path)) {
            throw new \InvalidArgumentException('.agents and .git are reserved metadata folders and cannot be managed here.');
        }
        if ($this->isSitePath($path, true)) {
            throw new \InvalidArgumentException('Site folders and files are managed from the Sites section.');
        }

        return $path;
    }

    private function visibleEntries(string $directory): array
    {
        $entries = [];
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (PathGuard::isReservedMetadataName($entry) || $this->isReservedMetadataPath($path) || $this->isSitePath($path, is_dir($path))) {
                continue;
            }

            $entries[] = $entry;
        }

        return $entries;
    }

    private function isSitePath(string $path, ?bool $targetIsDirectory = null): bool
    {
        if ($this->allowSitePaths) {
            return false;
        }

        $path = rtrim($path, DIRECTORY_SEPARATOR);
        if ($path === '') {
            return false;
        }

        $appRoot = realpath(APP_ROOT) ?: rtrim(APP_ROOT, DIRECTORY_SEPARATOR);
        foreach ($this->websiteRootPaths() as $root) {
            if ($path !== $root && !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                continue;
            }
            if ($path === $root) {
                return false;
            }

            $relative = substr($path, strlen($root) + 1);
            $parts = explode(DIRECTORY_SEPARATOR, $relative, 2);
            $siteName = $parts[0] ?? '';
            if ($siteName === '' || $this->isExcludedSiteName($siteName)) {
                continue;
            }

            $siteRoot = $root . DIRECTORY_SEPARATOR . $siteName;
            if ($this->overlapsAppRoot($siteRoot, $appRoot)) {
                continue;
            }

            if (is_dir($siteRoot)) {
                return true;
            }

            if ($targetIsDirectory === true && $path === $siteRoot) {
                return true;
            }
        }

        return false;
    }

    private function isReservedMetadataPath(string $path): bool
    {
        $doctorRuntime = realpath(STORAGE_PATH . '/doctor');
        $realPath = realpath($path) ?: rtrim($path, DIRECTORY_SEPARATOR);
        if ($doctorRuntime !== false && ($realPath === $doctorRuntime || str_starts_with($realPath, $doctorRuntime . DIRECTORY_SEPARATOR))) {
            return true;
        }
        foreach (explode(DIRECTORY_SEPARATOR, trim($path, DIRECTORY_SEPARATOR)) as $part) {
            if (PathGuard::isReservedMetadataName($part)) {
                return true;
            }
        }

        return false;
    }

    private function isReservedMetadataArchivePath(string $path): bool
    {
        foreach (explode('/', trim(str_replace('\\', '/', $path), '/')) as $part) {
            if (PathGuard::isReservedMetadataName($part)) {
                return true;
            }
        }

        return false;
    }

    private function websiteRootPaths(): array
    {
        if ($this->websiteRootPaths !== null) {
            return $this->websiteRootPaths;
        }

        $configured = EnvironmentConfig::paths('document_roots');

        $roots = [];
        foreach ($configured as $root) {
            try {
                $roots[] = PathGuard::assertAllowedDirectory((string) $root);
            } catch (\Throwable) {
                continue;
            }
        }

        return $this->websiteRootPaths = array_values(array_unique($roots));
    }

    private function overlapsAppRoot(string $path, string $appRoot): bool
    {
        $realPath = realpath($path) ?: rtrim($path, DIRECTORY_SEPARATOR);
        return $realPath === $appRoot
            || str_starts_with($realPath, $appRoot . DIRECTORY_SEPARATOR)
            || str_starts_with($appRoot, $realPath . DIRECTORY_SEPARATOR);
    }

    private function isExcludedSiteName(string $name): bool
    {
        return $name === '' || str_starts_with($name, '.') || str_starts_with($name, 'admin_terminal_opened_');
    }

    private function breadcrumbs(string $path): array
    {
        $crumbs = [];
        foreach ($this->roots() as $root) {
            if ($path === $root || str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
                $crumbs[] = ['label' => basename($root) ?: $root, 'path' => $root];
                $relative = trim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
                $accum = $root;
                foreach ($relative === '' ? [] : explode(DIRECTORY_SEPARATOR, $relative) as $part) {
                    $accum .= DIRECTORY_SEPARATOR . $part;
                    $crumbs[] = ['label' => $part, 'path' => $accum];
                }
                break;
            }
        }

        return $crumbs;
    }

    private function copyRecursive(string $source, string $target): void
    {
        if (is_file($source)) {
            if (!@copy($source, $target)) {
                throw new \RuntimeException('Unable to copy file.');
            }
            return;
        }

        if (!is_dir($target) && !mkdir($target, 0775, true) && !is_dir($target)) {
            throw new \RuntimeException('Unable to create target folder.');
        }
        foreach (scandir($source) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $source . DIRECTORY_SEPARATOR . $entry;
            if (PathGuard::isReservedMetadataName($entry) || $this->isReservedMetadataPath($child) || $this->isSitePath($child, is_dir($child))) {
                continue;
            }
            $this->copyRecursive($child, $target . DIRECTORY_SEPARATOR . $entry);
        }
    }

    private function extractZipSafely(ZipArchive $zip, string $target): bool
    {
        $targetRoot = rtrim($target, DIRECTORY_SEPARATOR);
        for ($index = 0; $index < $zip->numFiles; $index++) {
            $entryName = $zip->getNameIndex($index);
            if ($entryName === false) {
                continue;
            }

            $normalized = str_replace('\\', '/', $entryName);
            $relative = trim($normalized, '/');
            if ($relative === '' || str_contains($relative, "\0") || $this->isReservedMetadataArchivePath($relative)) {
                continue;
            }

            $parts = array_filter(explode('/', $relative), static fn (string $part): bool => $part !== '');
            if (!$parts || in_array('..', $parts, true)) {
                throw new \InvalidArgumentException('Archive contains an invalid path.');
            }

            $destination = $targetRoot . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
            if (str_ends_with($normalized, '/')) {
                if (!is_dir($destination) && !@mkdir($destination, 0775, true) && !is_dir($destination)) {
                    return false;
                }
                $this->assertVisibleDirectory($destination);
                continue;
            }

            $directory = dirname($destination);
            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                return false;
            }
            $this->assertVisibleDirectory($directory);
            $this->assertVisiblePath($destination, false);

            $input = $zip->getStream($entryName);
            if (!is_resource($input)) {
                return false;
            }

            $output = @fopen($destination, 'wb');
            if (!is_resource($output)) {
                fclose($input);
                return false;
            }

            $copied = stream_copy_to_stream($input, $output);
            fclose($input);
            fclose($output);
            if ($copied === false) {
                return false;
            }
            @chmod($destination, 0644);
        }

        return true;
    }

    private function removeRecursive(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            if (!@unlink($path)) {
                throw new \RuntimeException('Unable to remove file.');
            }
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeRecursive($path . DIRECTORY_SEPARATOR . $entry);
        }

        if (!@rmdir($path)) {
            throw new \RuntimeException('Unable to remove folder.');
        }
    }

    private function trashPath(string $path): string
    {
        $trashRoot = STORAGE_PATH . '/cache/trash';
        if (!is_dir($trashRoot) && !mkdir($trashRoot, 0775, true) && !is_dir($trashRoot)) {
            throw new \RuntimeException('Unable to create trash directory.');
        }

        $base = date('Ymd_His') . '_' . basename($path);
        $target = $trashRoot . DIRECTORY_SEPARATOR . $base;
        $counter = 1;
        while (file_exists($target)) {
            $target = $trashRoot . DIRECTORY_SEPARATOR . $base . '_' . $counter;
            $counter++;
        }

        return $target;
    }

    private function stageUpload(string $tmpPath, string $name): string
    {
        $stagingRoot = STORAGE_PATH . '/cache/uploads';
        if (!is_dir($stagingRoot) && !mkdir($stagingRoot, 0775, true) && !is_dir($stagingRoot)) {
            throw new \RuntimeException('Unable to create upload staging directory.');
        }

        $staged = $stagingRoot . DIRECTORY_SEPARATOR . bin2hex(random_bytes(8)) . '_' . PathGuard::safeName($name);
        if (!move_uploaded_file($tmpPath, $staged)) {
            throw new \RuntimeException('Unable to stage upload for elevated write.');
        }

        return $staged;
    }

    public function canUsePrivilegedHelper(): bool
    {
        if (getenv('SYSADMIN_FILE_CONTROL_ELEVATED') === '1') {
            return false;
        }

        return is_file('/usr/bin/sudo') && is_executable(APP_ROOT . '/scripts/service-control');
    }

    private function runPrivileged(string $action, array $payload): array
    {
        if (!$this->canUsePrivilegedHelper()) {
            throw new \RuntimeException('Privileged file helper is unavailable.');
        }

        $payload['_allow_sites'] = $this->allowSitePaths;
        $encodedPayload = base64_encode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $command = ['/usr/bin/sudo', '-n', APP_ROOT . '/scripts/service-control', 'file', $action, $encodedPayload];

        if (class_exists(Process::class)) {
            $process = new Process($command, APP_ROOT);
            $process->setTimeout(120);
            $process->run();
            $output = $process->getOutput();
            $error = $process->getErrorOutput();
            $exitCode = $process->getExitCode();
        } else {
            $descriptor = [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ];
            $proc = proc_open($command, $descriptor, $pipes, APP_ROOT);
            if (!is_resource($proc)) {
                throw new \RuntimeException('Unable to start privileged file helper.');
            }
            $output = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($proc);
        }

        if ($exitCode !== 0) {
            $message = trim($error) !== '' ? trim($error) : trim($output);
            throw new \RuntimeException($message !== '' ? $message : 'Privileged file helper failed.');
        }

        try {
            $decoded = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Privileged file helper returned invalid JSON.', 0, $exception);
        }

        if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
            throw new \RuntimeException((string) ($decoded['error'] ?? 'Privileged file helper failed.'));
        }

        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    private function assertEditableTextFile(string $path): void
    {
        if (!is_file($path)) {
            throw new \InvalidArgumentException('Select a file to edit.');
        }
        if (!is_readable($path)) {
            throw new \RuntimeException('File is not readable.');
        }
        if (!$this->isTextFile($path)) {
            throw new \InvalidArgumentException('Only text files can be edited.');
        }
    }

    private function readEditableContent(string $path): string
    {
        $maxBytes = (int) config_value('security.max_file_edit_bytes', 1048576);
        $size = filesize($path);
        if ($size !== false && $size > $maxBytes) {
            throw new \RuntimeException('File is too large to edit in the browser.');
        }

        $content = file_get_contents($path);
        if ($content === false) {
            throw new \RuntimeException('Unable to read file.');
        }

        if ($content !== '' && preg_match('//u', $content) !== 1) {
            throw new \RuntimeException('File is not valid UTF-8 text.');
        }

        return $content;
    }

    private function readPreviewContent(string $path): string
    {
        $content = file_get_contents($path, false, null, 0, 65536);
        if ($content === false) {
            return '';
        }

        if ($content !== '' && preg_match('//u', $content) !== 1) {
            return 'Preview is unavailable because this file is not valid UTF-8 text.';
        }

        return $content;
    }

    private function isTextFile(string $path): bool
    {
        $mime = function_exists('mime_content_type') ? (mime_content_type($path) ?: '') : '';
        if (str_starts_with($mime, 'text/')) {
            return true;
        }

        $textMimes = [
            'application/javascript',
            'application/json',
            'application/sql',
            'application/x-empty',
            'application/x-httpd-php',
            'application/x-php',
            'application/x-sh',
            'application/xml',
            'inode/x-empty',
        ];

        if (in_array($mime, $textMimes, true)) {
            return true;
        }

        return preg_match('/\.(conf|css|csv|env|html?|ini|js|json|log|md|php|py|rb|sh|sql|tsx?|txt|xml|ya?ml)$/i', $path) === 1;
    }

    private function addToZip(ZipArchive $zip, string $path, string $localName): void
    {
        if (is_file($path)) {
            $zip->addFile($path, $localName);
            return;
        }
        $zip->addEmptyDir($localName);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (PathGuard::isReservedMetadataName($entry) || $this->isSitePath($child, is_dir($child))) {
                continue;
            }
            $this->addToZip($zip, $child, $localName . '/' . $entry);
        }
    }
}
