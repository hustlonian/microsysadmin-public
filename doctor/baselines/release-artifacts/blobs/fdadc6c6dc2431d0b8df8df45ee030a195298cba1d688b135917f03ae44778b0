<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Reporting;

use SysAdmin\Doctor\engine\Filesystem\SafeFilesystem;
use SysAdmin\Doctor\engine\Logging\DoctorRepository;
use SysAdmin\Doctor\engine\Security\SecretRedactor;
use SysAdmin\Doctor\engine\Support\Value\ReportResult;

final readonly class ReportBuilder
{
    public function __construct(
        private SafeFilesystem $filesystem,
        private SecretRedactor $redactor,
        private DoctorRepository $repository,
        private string $reportRoot,
    ) {
    }

    public function generate(string $executionId, array $data, string $format): ReportResult
    {
        if (!in_array($format, ['json', 'text', 'html', 'pdf-html'], true)) {
            throw new \InvalidArgumentException('Unsupported Doctor report format.');
        }
        $safe = (array) $this->redactor->value($data);
        $content = match ($format) {
            'json' => json_encode($safe, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            'text' => $this->text($safe),
            'html' => $this->html($safe, false),
            'pdf-html' => $this->html($safe, true),
        };
        $extension = $format === 'json' ? 'json' : ($format === 'text' ? 'txt' : 'html');
        $directory = $this->reportRoot . DIRECTORY_SEPARATOR . $executionId;
        $this->filesystem->createDirectory($directory, 0750);
        $path = $directory . DIRECTORY_SEPARATOR . $format . '.' . $extension;
        $this->filesystem->atomicWrite($path, $content, 0640);
        $hash = hash('sha256', $content);
        $this->repository->recordReport($executionId, $format, $path, $hash);
        return new ReportResult(['execution_id' => $executionId, 'format' => $format, 'path' => $path, 'sha256' => $hash, 'bytes' => strlen($content), 'created_at' => gmdate(DATE_ATOM)]);
    }

    private function text(array $data): string
    {
        $score = (array) ($data['after_health_score'] ?? $data['before_health_score'] ?? []);
        $lines = [
            'MICROSYSADMIN DOCTOR REPORT',
            str_repeat('=', 30),
            'Execution: ' . ($data['execution_id'] ?? 'unknown'),
            'Generated: ' . gmdate(DATE_ATOM),
            'Mode: ' . ($data['mode'] ?? 'scan'),
            'Project: ' . ($data['project_path'] ?? ''),
            'Duration: ' . ($data['duration_ms'] ?? 0) . ' ms',
            'Health: ' . ($score['percentage'] ?? 'n/a') . '% (' . ($score['grade'] ?? 'n/a') . ')',
            'Modules: ' . implode(', ', (array) ($data['modules_executed'] ?? [])),
            '',
            'SUMMARY',
            'Checks: ' . count((array) ($data['checks'] ?? [])),
            'Diagnoses: ' . count((array) ($data['diagnoses'] ?? [])),
            'Plans: ' . count((array) ($data['plans'] ?? [])),
            'Repairs: ' . count((array) ($data['repairs'] ?? [])),
            '',
            'CHECK RESULTS',
        ];
        foreach ((array) ($data['checks'] ?? []) as $check) {
            $meta = (array) ($check['metadata'] ?? []);
            $lines[] = sprintf('[%s] %s %s - %s', $check['status'] ?? 'UNKNOWN', $meta['check_id'] ?? '', $meta['name'] ?? '', $check['message'] ?? '');
        }
        $lines[] = '';
        $lines[] = 'DIAGNOSES';
        foreach ((array) ($data['diagnoses'] ?? []) as $diagnosis) {
            if (!empty($diagnosis['condition_detected'])) {
                $lines[] = sprintf('[%.0f%%] %s - %s', ((float) ($diagnosis['confidence'] ?? 0)) * 100, $diagnosis['diagnosis_id'] ?? '', $diagnosis['probable_cause'] ?? '');
            }
        }
        $lines[] = '';
        $lines[] = 'REPAIR PLANS';
        foreach ((array) ($data['plans'] ?? []) as $plan) {
            $lines[] = sprintf('[%s] %s', ($plan['policy']['allowed'] ?? false) ? 'AVAILABLE' : 'BLOCKED', $plan['repair_id'] ?? '');
            $lines[] = json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $lines[] = '';
        $lines[] = 'REPAIRS, BACKUPS, VERIFICATION, AND ROLLBACK';
        foreach ((array) ($data['repairs'] ?? []) as $repair) {
            $lines[] = sprintf('[%s] %s - %s', $repair['status'] ?? 'UNKNOWN', $repair['repair_id'] ?? '', $repair['message'] ?? '');
            $lines[] = json_encode($repair, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        $lines[] = '';
        $lines[] = 'MANUAL ATTENTION: ' . ($score['manual_attention_items'] ?? 0);
        $lines[] = '';
        $lines[] = 'BEFORE AND AFTER HEALTH';
        $lines[] = json_encode(['before' => $data['before_health_score'] ?? null, 'after' => $data['after_health_score'] ?? null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $lines[] = '';
        $lines[] = 'SANITIZED ENVIRONMENT';
        $lines[] = json_encode($data['environment'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $lines[] = '';
        $lines[] = 'SANITIZED AUDIT TRAIL';
        $lines[] = json_encode($data['audit_trail'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        return implode("\n", $lines) . "\n";
    }

    private function html(array $data, bool $pdf): string
    {
        $escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $score = (array) ($data['after_health_score'] ?? $data['before_health_score'] ?? []);
        $rows = '';
        foreach ((array) ($data['checks'] ?? []) as $check) {
            $meta = (array) ($check['metadata'] ?? []);
            $rows .= '<tr><td>' . $escape($check['status'] ?? '') . '</td><td>' . $escape($meta['check_id'] ?? '') . '</td><td>' . $escape($meta['name'] ?? '') . '</td><td>' . $escape($check['message'] ?? '') . '<pre>' . $escape(json_encode($check['evidence'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></td></tr>';
        }
        $repairRows = '';
        foreach ((array) ($data['repairs'] ?? []) as $repair) {
            $repairRows .= '<tr><td>' . $escape($repair['status'] ?? '') . '</td><td>' . $escape($repair['repair_id'] ?? '') . '</td><td>' . $escape($repair['message'] ?? '') . '</td></tr>';
        }
        $pdfCss = $pdf ? '@page{size:A4;margin:14mm}body{font-size:10pt}.no-print{display:none}' : '';
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Doctor report ' . $escape($data['execution_id'] ?? '') . '</title><style>'
            . 'body{font-family:system-ui,sans-serif;color:#18212f;margin:2rem}h1,h2{color:#17345f}.score{font-size:2rem;font-weight:800}.meta{display:grid;grid-template-columns:repeat(2,1fr);gap:.4rem 1rem}table{border-collapse:collapse;width:100%;margin:1rem 0}th,td{border:1px solid #ccd4df;padding:.45rem;text-align:left;vertical-align:top}th{background:#edf2f7}.PASS,.VERIFIED{color:#177245}.FAIL,.ERROR,.FAILED{color:#b42318}pre{white-space:pre-wrap}' . $pdfCss
            . '</style></head><body><h1>MicroSysAdmin Doctor Report</h1><div class="score">' . $escape($score['percentage'] ?? 'n/a') . '% ' . $escape($score['grade'] ?? '') . '</div><div class="meta"><div><strong>Execution</strong> ' . $escape($data['execution_id'] ?? '') . '</div><div><strong>Generated</strong> ' . $escape(gmdate(DATE_ATOM)) . '</div><div><strong>Mode</strong> ' . $escape($data['mode'] ?? '') . '</div><div><strong>Project</strong> ' . $escape($data['project_path'] ?? '') . '</div><div><strong>Duration</strong> ' . $escape($data['duration_ms'] ?? 0) . ' ms</div><div><strong>Modules</strong> ' . $escape(implode(', ', (array) ($data['modules_executed'] ?? []))) . '</div></div><h2>Checks and detection evidence</h2><table><thead><tr><th>Status</th><th>ID</th><th>Check</th><th>Evidence summary</th></tr></thead><tbody>' . $rows . '</tbody></table><h2>Diagnosed causes</h2><pre>' . $escape(json_encode($data['diagnoses'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre><h2>Available, approved, blocked, and report-only plans</h2><pre>' . $escape(json_encode($data['plans'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre><h2>Repairs, backups, verification, and rollback</h2><table><thead><tr><th>Status</th><th>ID</th><th>Result</th></tr></thead><tbody>' . $repairRows . '</tbody></table><pre>' . $escape(json_encode($data['repairs'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre><h2>Before-and-after health</h2><pre>' . $escape(json_encode(['before' => $data['before_health_score'] ?? null, 'after' => $data['after_health_score'] ?? null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre><h2>Manual-attention items</h2><p>' . $escape($score['manual_attention_items'] ?? 0) . '</p><h2>Sanitized environment</h2><pre>' . $escape(json_encode($data['environment'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre><h2>Sanitized audit trail</h2><pre>' . $escape(json_encode($data['audit_trail'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></body></html>';
    }
}
