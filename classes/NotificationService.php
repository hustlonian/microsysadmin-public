<?php

declare(strict_types=1);

namespace SysAdmin;

use PHPMailer\PHPMailer\PHPMailer;

final class NotificationService implements \SysAdmin\Contracts\NotificationServiceInterface
{
    public function configuration(): array
    {
        $configured = config_value('mail', []);
        try {
            $settings = \SysAdmin\Models\Setting::all();
        } catch (\Throwable) {
            $settings = [];
        }
        return [
            'enabled' => ($settings['smtp_enabled'] ?? (!empty($configured['enabled']) ? '1' : '0')) === '1',
            'host' => (string) ($settings['smtp_host'] ?? $configured['host'] ?? 'localhost'),
            'port' => (int) ($settings['smtp_port'] ?? $configured['port'] ?? 25),
            'username' => (string) ($settings['smtp_username'] ?? $configured['username'] ?? ''),
            'password' => $this->password($settings, $configured),
            'password_configured' => !empty($settings['smtp_password_encrypted']) || !empty($configured['password']),
            'encryption' => (string) ($settings['smtp_encryption'] ?? $configured['encryption'] ?? ''),
            'from_email' => (string) ($settings['smtp_from_email'] ?? $configured['from_email'] ?? 'microsysadmin@localhost'),
            'from_name' => (string) ($settings['smtp_from_name'] ?? $configured['from_name'] ?? 'MicroSysAdmin'),
        ];
    }

    public function saveConfiguration(array $input): array
    {
        $host = trim((string) ($input['host'] ?? ''));
        $port = (int) ($input['port'] ?? 0);
        $username = trim((string) ($input['username'] ?? ''));
        $encryption = strtolower(trim((string) ($input['encryption'] ?? '')));
        $fromEmail = trim((string) ($input['from_email'] ?? ''));
        $fromName = trim((string) ($input['from_name'] ?? 'MicroSysAdmin'));
        if ($host === '' || strlen($host) > 255) {
            throw new \InvalidArgumentException('SMTP host is invalid.');
        }
        if ($port < 1 || $port > 65535) {
            throw new \InvalidArgumentException('SMTP port is invalid.');
        }
        if (!in_array($encryption, ['', 'tls', 'ssl'], true)) {
            throw new \InvalidArgumentException('SMTP encryption must be none, TLS, or SSL.');
        }
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('SMTP sender email is invalid.');
        }
        foreach ([
            'smtp_enabled' => !empty($input['enabled']) ? '1' : '0',
            'smtp_host' => $host,
            'smtp_port' => (string) $port,
            'smtp_username' => $username,
            'smtp_encryption' => $encryption,
            'smtp_from_email' => $fromEmail,
            'smtp_from_name' => substr($fromName, 0, 190),
        ] as $key => $value) {
            \SysAdmin\Models\Setting::set($key, $value);
        }
        if (isset($input['password']) && (string) $input['password'] !== '') {
            \SysAdmin\Models\Setting::set('smtp_password_encrypted', Crypto::encrypt((string) $input['password']));
        }
        AuditLogger::warning('notification.smtp.updated', ['host' => $host, 'port' => $port, 'enabled' => !empty($input['enabled'])]);
        $configuration = $this->configuration();
        unset($configuration['password']);
        return $configuration;
    }

    public function sendTest(string $to): array
    {
        LicenseManager::service()->assert('notifications.enabled');
        $mailConfig = $this->configuration();
        if (empty($mailConfig['enabled'])) {
            throw new \RuntimeException('Email notifications are disabled in config.');
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Recipient email is invalid.');
        }
        if (!class_exists(PHPMailer::class)) {
            throw new \RuntimeException('PHPMailer is not installed. Run composer install.');
        }

        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->Host = (string) $mailConfig['host'];
        $mailer->Port = (int) $mailConfig['port'];
        if (!empty($mailConfig['username'])) {
            $mailer->SMTPAuth = true;
            $mailer->Username = (string) $mailConfig['username'];
            $mailer->Password = (string) $mailConfig['password'];
        }
        if (!empty($mailConfig['encryption'])) {
            $mailer->SMTPSecure = (string) $mailConfig['encryption'];
        }
        $mailer->setFrom((string) $mailConfig['from_email'], (string) $mailConfig['from_name']);
        $mailer->addAddress($to);
        $appName = (string) config_value('app.name', 'MicroSysAdmin');
        $mailer->Subject = $appName . ' notification test';
        $mailer->Body = $appName . ' email notifications are configured and working.';
        $mailer->send();
        AuditLogger::info('notification.test', ['to' => $to]);

        return ['ok' => true];
    }

    private function password(array $settings, array $configured): string
    {
        if (!empty($settings['smtp_password_encrypted'])) {
            try {
                return Crypto::decrypt((string) $settings['smtp_password_encrypted']);
            } catch (\Throwable) {
                return '';
            }
        }
        return (string) ($configured['password'] ?? '');
    }
}
