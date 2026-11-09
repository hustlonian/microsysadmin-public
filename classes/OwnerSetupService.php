<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Models\Setting;

/** Owner-facing configuration state. Secrets are written encrypted and never returned. */
final class OwnerSetupService
{
    private const STORAGE_KEY = 'owner_setup.configuration.v1';

    public function overview(): array
    {
        $config = $this->stored();
        return [
            'hosting' => [
                'state' => $this->hostingState($config['hosting'] ?? []),
                'configuration' => $this->publicFields($config['hosting'] ?? [], ['provider', 'environment', 'domain', 'application_url', 'deployment_method', 'control_panel', 'php_requirement', 'database_requirement', 'scheduler_requirement', 'queue_requirement', 'ssl_requirement', 'backup_destination', 'restore_verified']),
                'credential_saved' => !empty($config['hosting']['deploy_credential']),
            ],
            'email' => [
                'state' => $this->emailState($config['email'] ?? []),
                'configuration' => $this->publicFields($config['email'] ?? [], ['provider', 'owner_email', 'sender_email', 'sender_name', 'reply_to', 'additional_recipients', 'transport']),
                'credential_saved' => !empty($config['email']['provider_credential']),
                'delivery_status' => (string) ($config['email']['delivery_status'] ?? 'NOT_TESTED'),
            ],
            'stripe' => [
                'test_state' => $this->stripeTestState($config['stripe'] ?? []),
                'live_state' => $this->stripeLiveState($config['stripe'] ?? []),
                'test_credential_saved' => !empty($config['stripe']['test_secret']),
                'live_credential_saved' => !empty($config['stripe']['live_secret']),
                'live_payments_enabled' => false,
            ],
        ];
    }

    public function saveHosting(array $input): array
    {
        $provider = $this->choice($input['provider'] ?? '', ['cpanel', 'ssh', 'managed_host', 'other'], 'Hosting provider');
        $environment = $this->choice($input['environment'] ?? '', ['shared', 'vps', 'dedicated', 'cloud', 'local', 'other'], 'Hosting environment');
        $domain = $this->domain((string) ($input['domain'] ?? ''));
        $applicationUrl = $this->url((string) ($input['application_url'] ?? ''));
        $deploymentMethod = $this->choice($input['deployment_method'] ?? '', ['sftp', 'ssh', 'cpanel', 'git', 'manual', 'other'], 'Deployment method');
        $config = $this->stored();
        $hosting = $this->publicFields($input, ['provider', 'environment', 'domain', 'application_url', 'deployment_method', 'control_panel', 'php_requirement', 'database_requirement', 'scheduler_requirement', 'queue_requirement', 'ssl_requirement', 'backup_destination', 'restore_verified', 'deployment_scope', 'database_host', 'database_name', 'database_username']);
        $hosting['provider'] = $provider;
        $hosting['environment'] = $environment;
        $hosting['domain'] = $domain;
        $hosting['application_url'] = $applicationUrl;
        $hosting['deployment_method'] = $deploymentMethod;
        if (array_key_exists('deploy_credential', $input) && (string) $input['deploy_credential'] !== '') {
            $hosting['deploy_credential'] = Crypto::encrypt((string) $input['deploy_credential']);
        } elseif (!empty($config['hosting']['deploy_credential'])) {
            $hosting['deploy_credential'] = $config['hosting']['deploy_credential'];
        }
        $config['hosting'] = $hosting;
        $this->persist($config, 'owner_setup.hosting.saved');
        return $this->overview()['hosting'];
    }

    public function saveEmail(array $input): array
    {
        $ownerEmail = $this->email((string) ($input['owner_email'] ?? ''));
        $senderEmail = $this->email((string) ($input['sender_email'] ?? ''));
        $provider = $this->choice($input['provider'] ?? '', ['smtp', 'postmark', 'sendgrid', 'ses', 'other'], 'Email provider');
        $config = $this->stored();
        $email = $this->publicFields($input, ['provider', 'owner_email', 'sender_email', 'sender_name', 'reply_to', 'additional_recipients', 'transport']);
        $email['provider'] = $provider;
        $email['owner_email'] = $ownerEmail;
        $email['sender_email'] = $senderEmail;
        if (!empty($email['reply_to'])) $email['reply_to'] = $this->email((string) $email['reply_to']);
        if (!empty($email['additional_recipients'])) {
            $recipients = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $email['additional_recipients']) ?: []));
            foreach ($recipients as $recipient) $this->email($recipient);
            $email['additional_recipients'] = implode(', ', array_values(array_unique($recipients)));
        }
        if (array_key_exists('provider_credential', $input) && (string) $input['provider_credential'] !== '') {
            $email['provider_credential'] = Crypto::encrypt((string) $input['provider_credential']);
        } elseif (!empty($config['email']['provider_credential'])) {
            $email['provider_credential'] = $config['email']['provider_credential'];
        }
        $email['delivery_status'] = 'NOT_TESTED';
        $config['email'] = $email;
        $this->persist($config, 'owner_setup.email.saved');
        return $this->overview()['email'];
    }

    public function saveStripeCredentials(string $mode, string $secretKey, string $webhookSecret = ''): array
    {
        if (!in_array($mode, ['test', 'live'], true)) {
            throw new \InvalidArgumentException('Stripe mode must be test or live.');
        }
        $prefix = $mode === 'test' ? 'sk_test_' : 'sk_live_';
        if (!str_starts_with($secretKey, $prefix) || strlen($secretKey) < strlen($prefix) + 8) {
            throw new \InvalidArgumentException('Stripe secret key does not match the selected mode.');
        }
        if ($webhookSecret !== '' && !str_starts_with($webhookSecret, 'whsec_')) {
            throw new \InvalidArgumentException('Stripe webhook signing secret is invalid.');
        }
        $config = $this->stored();
        $stripe = $config['stripe'] ?? [];
        $stripe[$mode . '_secret'] = Crypto::encrypt($secretKey);
        if ($webhookSecret !== '') {
            $stripe[$mode . '_webhook_secret'] = Crypto::encrypt($webhookSecret);
        }
        if ($mode === 'live') {
            // Credential presence never activates charges or claims verified configuration.
            $stripe['live_payments_enabled'] = false;
        }
        $config['stripe'] = $stripe;
        $this->persist($config, 'owner_setup.stripe.' . $mode . '_credentials_saved');
        return $this->overview()['stripe'];
    }

    private function stored(): array
    {
        $encoded = (string) (Setting::all()[self::STORAGE_KEY] ?? '');
        if ($encoded === '') {
            return [];
        }
        $data = json_decode($encoded, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Owner setup configuration is invalid.');
        }
        return $data;
    }

    private function persist(array $config, string $auditEvent): void
    {
        Setting::set(self::STORAGE_KEY, json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        AuditLogger::info($auditEvent, ['actor_id' => Auth::id()]);
    }

    private function hostingState(array $hosting): string
    {
        if (empty($hosting['provider'])) return 'HOSTING_NOT_CONFIGURED';
        if (empty($hosting['deploy_credential'])) return 'CREDENTIALS_REQUIRED';
        return 'CREDENTIALS_REQUIRED'; // Saved credentials require a separate, read-only verification step.
    }

    private function emailState(array $email): string
    {
        if (empty($email['provider'])) return 'EMAIL_NOT_CONFIGURED';
        if (empty($email['provider_credential'])) return 'EMAIL_CREDENTIALS_REQUIRED';
        return 'EMAIL_CONFIGURED_NOT_TESTED';
    }

    private function stripeTestState(array $stripe): string
    {
        return empty($stripe['test_secret']) ? 'TEST_CREDENTIALS_REQUIRED' : 'TEST_CREDENTIALS_SAVED';
    }

    private function stripeLiveState(array $stripe): string
    {
        return empty($stripe['live_secret']) ? 'LIVE_CREDENTIALS_REQUIRED' : 'LIVE_CREDENTIALS_SAVED_OWNER_ACTIVATION_REQUIRED';
    }

    private function choice(mixed $value, array $choices, string $label): string
    {
        $value = strtolower(trim((string) $value));
        if (!in_array($value, $choices, true)) {
            throw new \InvalidArgumentException($label . ' is invalid.');
        }
        return $value;
    }

    private function email(string $value): string
    {
        $value = trim($value);
        if (!filter_var($value, FILTER_VALIDATE_EMAIL)) throw new \InvalidArgumentException('Email address is invalid.');
        return $value;
    }

    private function domain(string $value): string
    {
        $value = strtolower(trim($value));
        if ($value !== '' && (strlen($value) > 253 || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value))) {
            throw new \InvalidArgumentException('Domain is invalid.');
        }
        return $value;
    }

    private function url(string $value): string
    {
        $value = trim($value);
        if ($value !== '' && (!filter_var($value, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($value, PHP_URL_SCHEME)), ['https', 'http'], true))) {
            throw new \InvalidArgumentException('Application URL must be a valid HTTP or HTTPS URL.');
        }
        return $value;
    }

    private function publicFields(array $data, array $fields): array
    {
        return array_intersect_key($data, array_flip($fields));
    }
}
