<?php

declare(strict_types=1);

namespace SysAdmin;

final class LogService implements \SysAdmin\Contracts\LogOperationsInterface
{
    public function files(): array
    {
        $files = [];
        foreach ((array) config_value('paths.log_files', []) as $label => $path) {
            $path = str_replace(
                ['{APP_ROOT}', '{XAMPP_ROOT}'],
                [APP_ROOT, EnvironmentConfig::path('xampp_root')],
                (string) $path
            );
            foreach (glob($path) ?: [$path] as $candidate) {
                if (is_file($candidate) && is_readable($candidate)) {
                    $files[$label . ': ' . basename($candidate)] = $candidate;
                }
            }
        }

        $files[config_value('app.name', 'MicroSysAdmin') . ' app'] = STORAGE_PATH . '/logs/app.log';
        return $files;
    }

    public function read(string $label, string $search = '', int $limit = 300): array
    {
        $files = $this->files();
        $path = $files[$label] ?? reset($files);
        $lines = $this->tail((string) $path, $limit);
        $search = trim($search);
        $rows = [];
        foreach ($lines as $line) {
            if ($search !== '' && stripos($line, $search) === false) {
                continue;
            }
            $level = preg_match('/fatal|critical|error|exception/i', $line) ? 'error' : (preg_match('/warn|notice/i', $line) ? 'warning' : 'info');
            $rows[] = ['level' => $level, 'line' => $line];
        }

        return ['label' => $label, 'path' => $path, 'rows' => $rows];
    }

    public function recentEvents(int $limit = 10): array
    {
        $rows = [];
        foreach ($this->files() as $label => $path) {
            foreach ($this->tail((string) $path, 40) as $line) {
                if (preg_match('/fatal|critical|error|exception|warn/i', $line)) {
                    $rows[] = ['source' => $label, 'line' => $line];
                }
                if (count($rows) >= $limit) {
                    return $rows;
                }
            }
        }

        return $rows;
    }

    public function analyze(string $label): array
    {
        $read = $this->read($label, '', 1000);
        $counts = ['error' => 0, 'warning' => 0, 'info' => 0];
        $signals = [];
        foreach ($read['rows'] as $row) {
            $counts[$row['level']]++;
            if ($row['level'] !== 'info') {
                $signals[] = $row['line'];
            }
        }

        return [
            'counts' => $counts,
            'summary' => $counts['error'] > 0
                ? 'Errors are present. Review the latest failing lines before restarting services.'
                : ($counts['warning'] > 0 ? 'Warnings are present but no fatal errors were found.' : 'No error signals were found in the recent log window.'),
            'signals' => array_slice($signals, 0, 20),
        ];
    }

    private function tail(string $path, int $limit): array
    {
        if (!is_file($path) || !is_readable($path)) {
            return ["Log is unavailable: {$path}"];
        }

        $limit = max(1, min(5000, $limit));
        $handle = @fopen($path, 'rb');
        if ($handle === false || fseek($handle, 0, SEEK_END) !== 0) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            return ["Log is unavailable: {$path}"];
        }

        $position = ftell($handle);
        if ($position === false || $position === 0) {
            fclose($handle);
            return [];
        }

        $buffer = '';
        $chunkSize = 16384;
        $maxBytes = 4 * 1024 * 1024;
        while ($position > 0 && substr_count($buffer, "\n") <= $limit && strlen($buffer) < $maxBytes) {
            $readSize = min($chunkSize, $position);
            $position -= $readSize;
            if (fseek($handle, $position) !== 0) {
                break;
            }
            $chunk = fread($handle, $readSize);
            if ($chunk === false) {
                break;
            }
            $buffer = $chunk . $buffer;
        }
        fclose($handle);

        $lines = preg_split('/\R/', $buffer) ?: [];
        if ($position > 0 && $lines) {
            array_shift($lines);
        }
        $lines = array_values(array_filter(
            array_map(static fn (string $line): string => rtrim($line), $lines),
            static fn (string $line): bool => $line !== ''
        ));

        return array_slice($lines, -$limit);
    }
}
