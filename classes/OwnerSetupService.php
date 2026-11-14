<?php

declare(strict_types=1);

namespace SysAdmin;

use SysAdmin\Models\Setting;

/** Owner-facing configuration state. Secrets are written encrypted and never returned. */
final class OwnerSetupService
{
    private const STORAGE_KEY = 'owner_setup.configuration.v1';
    private const HOSTING_PUBLIC_FIELDS = ['provider', 'environment', 'domain', 'application_url', 'deployment_method', 'control_panel', 'php_requirement', 'database_requirement', 'scheduler_requirement', 'queue_requirement', 'ssl_requirement', 'backup_destination', 'restore_verified', 'deployment_scope', 'database_host', 'database_name', 'database_username'];
    private const EMAIL_PUBLIC_FIELDS = ['provider', 'owner_email', 'sender_email', 'sender_name', 'reply_to', 'additional_recipients', 'transport'];

    public function overview(): array
    {
        $config = $this->stored();
        return [
            'hosting' => [
                'state' => $this->hostingState($config['hosting'] ?? []),
                'configuration' => $this->publicFields($config['hosting'] ?? [], self::HOSTING_PUBLIC_FIELDS),
                'credential_saved' => !empty($config['hosting']['deploy_credential']),
                'last_verification' => $this->lastReceipt($config['hosting']['verifications'] ?? []),
            ],
            'email' => [
                'state' => $this->emailState($config['email'] ?? []),
                'configuration' => $this->publicFields($config['email'] ?? [], self::EMAIL_PUBLIC_FIELDS),
                'credential_saved' => !empty($config['email']['provider_credential']),
                'delivery_status' => (string) ($config['email']['delivery_status'] ?? 'NOT_TESTED'),
                'last_delivery' => $this->lastReceipt($config['email']['deliveries'] ?? []),
            ],
            'stripe' => [
                'test_state' => $this->stripeTestState($config['stripe'] ?? []),
                'live_state' => $this->stripeLiveState($config['stripe'] ?? []),
                'test_credential_saved' => !empty($config['stripe']['test_secret']),
                'live_credential_saved' => !empty($config['stripe']['live_secret']),
                'test_webhook_saved' => !empty($config['stripe']['test_webhook_secret']),
                'live_webhook_saved' => !empty($config['stripe']['live_webhook_secret']),
                'test_webhook_status' => empty($config['stripe']['test_webhook_receipts']) ? 'NOT_VERIFIED' : 'VERIFIED_IDEMPOTENT',
                'last_test_webhook' => $this->lastReceipt($config['stripe']['test_webhook_receipts'] ?? []),
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
        $hosting = $this->publicFields($input, self::HOSTING_PUBLIC_FIELDS);
        $hosting['provider'] = $provider;
        $hosting['environment'] = $environment;
        $hosting['domain'] = $domain;
        $hosting['application_url'] = $applicationUrl;
        $hosting['deployment_method'] = $deploymentMethod;
        $credential = (string) ($input['deploy_credential'] ?? '');
        $this->mutate(function (array &$config) use ($hosting, $credential): array {
            foreach ($config['hosting']['verifications'] ?? [] as $receipt) {
                if (($receipt['status'] ?? '') === 'HOSTING_VERIFICATION_PENDING') {
                    throw new \RuntimeException('Hosting verification is in progress; configuration cannot be changed.');
                }
            }
            $replacement = $hosting;
            if ($credential !== '') $replacement['deploy_credential'] = Crypto::encrypt($credential);
            elseif (!empty($config['hosting']['deploy_credential'])) $replacement['deploy_credential'] = $config['hosting']['deploy_credential'];
            $config['hosting'] = $replacement;
            return [];
        });
        AuditLogger::info('owner_setup.hosting.saved', ['actor_id' => Auth::id()]);
        return $this->overview()['hosting'];
    }

    public function saveEmail(array $input): array
    {
        $ownerEmail = $this->email((string) ($input['owner_email'] ?? ''));
        $senderEmail = $this->email((string) ($input['sender_email'] ?? ''));
        $provider = $this->choice($input['provider'] ?? '', ['smtp', 'postmark', 'sendgrid', 'ses', 'other'], 'Email provider');
        $email = $this->publicFields($input, self::EMAIL_PUBLIC_FIELDS);
        $email['provider'] = $provider;
        $email['owner_email'] = $ownerEmail;
        $email['sender_email'] = $senderEmail;
        if (!empty($email['reply_to'])) $email['reply_to'] = $this->email((string) $email['reply_to']);
        if (!empty($email['additional_recipients'])) {
            $recipients = array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) $email['additional_recipients']) ?: []));
            foreach ($recipients as $recipient) $this->email($recipient);
            $email['additional_recipients'] = implode(', ', array_values(array_unique($recipients)));
        }
        $credential = (string) ($input['provider_credential'] ?? '');
        $this->mutate(function (array &$config) use ($email, $credential): array {
            foreach ($config['email']['deliveries'] ?? [] as $receipt) {
                if (in_array($receipt['status'] ?? '', ['DELIVERY_PENDING', 'DELIVERY_OUTCOME_UNKNOWN_NO_RETRY'], true)) {
                    throw new \RuntimeException('Email delivery requires reconciliation before configuration can be changed.');
                }
            }
            $replacement = $email;
            if ($credential !== '') $replacement['provider_credential'] = Crypto::encrypt($credential);
            elseif (!empty($config['email']['provider_credential'])) $replacement['provider_credential'] = $config['email']['provider_credential'];
            $replacement['delivery_status'] = 'NOT_TESTED';
            $config['email'] = $replacement;
            return [];
        });
        AuditLogger::info('owner_setup.email.saved', ['actor_id' => Auth::id()]);
        return $this->overview()['email'];
    }

    public function saveStripeCredentials(string $mode, string $secretKey, string $webhookSecret = ''): array
    {
        if (!in_array($mode, ['test', 'live'], true)) {
            throw new \InvalidArgumentException('Stripe mode must be test or live.');
        }
        if (!preg_match('/\A(?:sk|rk)_' . $mode . '_[A-Za-z0-9_]{8,}\z/', $secretKey)) {
            throw new \InvalidArgumentException('Stripe API key does not match the selected mode.');
        }
        if ($webhookSecret !== '' && !str_starts_with($webhookSecret, 'whsec_')) {
            throw new \InvalidArgumentException('Stripe webhook signing secret is invalid.');
        }
        $this->mutate(function (array &$config) use ($mode, $secretKey, $webhookSecret): array {
            $stripe = $config['stripe'] ?? [];
            $secretField = $mode . '_secret';
            $webhookField = $mode . '_webhook_secret';
            $secretChanged = empty($stripe[$secretField]) || !hash_equals(Crypto::decrypt((string) $stripe[$secretField]), $secretKey);
            $webhookChanged = $webhookSecret !== ''
                && (empty($stripe[$webhookField]) || !hash_equals(Crypto::decrypt((string) $stripe[$webhookField]), $webhookSecret));
            $stripe[$secretField] = Crypto::encrypt($secretKey);
            if ($webhookSecret !== '') $stripe[$webhookField] = Crypto::encrypt($webhookSecret);
            if ($mode === 'test' && ($secretChanged || $webhookChanged)) unset($stripe['test_webhook_receipts']);
            if ($mode === 'live') {
                // Credential presence never activates charges or claims verified configuration.
                $stripe['live_payments_enabled'] = false;
            }
            $config['stripe'] = $stripe;
            return [];
        });
        AuditLogger::info('owner_setup.stripe.' . $mode . '_credentials_saved', ['actor_id' => Auth::id()]);
        return $this->overview()['stripe'];
    }

    /** Verify declared hosting requirements through a caller-supplied read-only probe. */
    public function verifyHostingCompatibility(string $requestId, callable $probe): array
    {
        $requestId = $this->requestId($requestId);
        $attempt = $this->mutate(function (array &$config) use ($requestId): array {
            $hosting =& $config['hosting'];
            if (empty($hosting['deploy_credential'])) {
                throw new \RuntimeException('Hosting credential is required before compatibility verification.');
            }
            $existing = $hosting['verifications'][$requestId] ?? null;
            if (($existing['status'] ?? '') === 'HOSTING_COMPATIBILITY_VERIFIED') {
                return ['receipt' => array_merge($existing, ['replayed' => true])];
            }
            if (isset($existing)) {
                throw new \RuntimeException('Hosting verification request identity is already recorded; use a new identity.');
            }
            $configuration = $this->publicFields($hosting, self::HOSTING_PUBLIC_FIELDS);
            $configurationHash = $this->configurationHash($configuration);
            $hosting['verifications'][$requestId] = [
                'request_id' => $requestId,
                'status' => 'HOSTING_VERIFICATION_PENDING',
                'configuration_sha256' => $configurationHash,
                'started_at' => gmdate('c'),
                'replayed' => false,
            ];
            return [
                'configuration' => $configuration,
                'configuration_sha256' => $configurationHash,
                'credential' => Crypto::decrypt((string) $hosting['deploy_credential']),
            ];
        });
        if (isset($attempt['receipt'])) return $attempt['receipt'];

        try {
            $result = $probe($attempt['configuration'], $attempt['credential'], $requestId);
            $required = ['php', 'database', 'scheduler', 'queue', 'ssl', 'backup', 'restore'];
            if (!is_array($result) || array_keys($result) !== $required || array_filter($result, static fn (mixed $value): bool => $value !== true) !== []) {
                throw new \RuntimeException('Hosting compatibility verification failed.');
            }
        } catch (\Throwable) {
            $this->mutate(function (array &$config) use ($requestId): array {
                if (($config['hosting']['verifications'][$requestId]['status'] ?? '') === 'HOSTING_VERIFICATION_PENDING') {
                    $config['hosting']['verifications'][$requestId]['status'] = 'HOSTING_VERIFICATION_FAILED';
                    $config['hosting']['verifications'][$requestId]['failed_at'] = gmdate('c');
                }
                return [];
            });
            AuditLogger::warning('owner_setup.hosting.verification_failed', ['actor_id' => Auth::id(), 'request_id' => $requestId]);
            throw new \RuntimeException('Hosting compatibility verification failed.');
        }

        $receipt = $this->mutate(function (array &$config) use ($requestId, $attempt): array {
            $hosting =& $config['hosting'];
            $pending = $hosting['verifications'][$requestId] ?? null;
            if (($pending['status'] ?? '') !== 'HOSTING_VERIFICATION_PENDING'
                || !hash_equals((string) ($pending['configuration_sha256'] ?? ''), $attempt['configuration_sha256'])
                || !hash_equals($this->configurationHash($this->publicFields($hosting, self::HOSTING_PUBLIC_FIELDS)), $attempt['configuration_sha256'])) {
                throw new \RuntimeException('Hosting configuration changed during verification.');
            }
            $receipt = [
                'request_id' => $requestId,
                'status' => 'HOSTING_COMPATIBILITY_VERIFIED',
                'configuration_sha256' => $attempt['configuration_sha256'],
                'verified_at' => gmdate('c'),
                'replayed' => false,
            ];
            $hosting['verifications'][$requestId] = $receipt;
            return $receipt;
        });
        AuditLogger::info('owner_setup.hosting.verified', ['actor_id' => Auth::id(), 'request_id' => $requestId]);
        return $receipt;
    }

    /** Send one synthetic/controlled transactional message through an injected adapter. */
    public function verifyEmailDelivery(string $requestId, callable $deliver): array
    {
        $requestId = $this->requestId($requestId);
        $attempt = $this->mutate(function (array &$config) use ($requestId): array {
            $email =& $config['email'];
            if (empty($email['provider_credential'])) {
                throw new \RuntimeException('Email credential is required before delivery verification.');
            }
            $existing = $email['deliveries'][$requestId] ?? null;
            if (in_array($existing['status'] ?? '', ['DELIVERED', 'DELIVERED_RECONCILED'], true)) {
                return ['receipt' => array_merge($existing, ['replayed' => true])];
            }
            foreach ($email['deliveries'] ?? [] as $receipt) {
                if (in_array($receipt['status'] ?? '', ['DELIVERY_PENDING', 'DELIVERY_OUTCOME_UNKNOWN_NO_RETRY'], true)) {
                    throw new \RuntimeException('A transactional email outcome requires reconciliation before another test.');
                }
            }
            $configuration = $this->publicFields($email, self::EMAIL_PUBLIC_FIELDS);
            $configurationHash = $this->configurationHash($configuration);
            $email['deliveries'][$requestId] = [
                'request_id' => $requestId,
                'status' => 'DELIVERY_PENDING',
                'configuration_sha256' => $configurationHash,
                'started_at' => gmdate('c'),
                'replayed' => false,
            ];
            $email['delivery_status'] = 'TEST_DELIVERY_PENDING';
            return [
                'configuration' => $configuration,
                'configuration_sha256' => $configurationHash,
                'credential' => Crypto::decrypt((string) $email['provider_credential']),
            ];
        });
        if (isset($attempt['receipt'])) return $attempt['receipt'];

        try {
            $result = $deliver($attempt['configuration'], $attempt['credential'], $requestId);
            $messageId = trim((string) ($result['provider_message_id'] ?? ''));
            if (($result['status'] ?? '') !== 'DELIVERED' || $messageId === '' || strlen($messageId) > 190) {
                throw new \RuntimeException('Transactional email delivery was not verified.');
            }
        } catch (\Throwable) {
            $this->mutate(function (array &$config) use ($requestId): array {
                if (($config['email']['deliveries'][$requestId]['status'] ?? '') === 'DELIVERY_PENDING') {
                    $config['email']['deliveries'][$requestId]['status'] = 'DELIVERY_OUTCOME_UNKNOWN_NO_RETRY';
                    $config['email']['deliveries'][$requestId]['failed_at'] = gmdate('c');
                    $config['email']['delivery_status'] = 'OUTCOME_UNKNOWN_NO_RETRY';
                }
                return [];
            });
            AuditLogger::warning('owner_setup.email.delivery_unknown', ['actor_id' => Auth::id(), 'request_id' => $requestId]);
            throw new \RuntimeException('Transactional email outcome is unknown; reconcile it before retrying.');
        }

        $receipt = $this->mutate(function (array &$config) use ($requestId, $attempt, $messageId): array {
            $email =& $config['email'];
            $pending = $email['deliveries'][$requestId] ?? null;
            if (($pending['status'] ?? '') !== 'DELIVERY_PENDING'
                || !hash_equals((string) ($pending['configuration_sha256'] ?? ''), $attempt['configuration_sha256'])
                || !hash_equals($this->configurationHash($this->publicFields($email, self::EMAIL_PUBLIC_FIELDS)), $attempt['configuration_sha256'])) {
                throw new \RuntimeException('Email configuration changed during delivery verification.');
            }
            $receipt = [
                'request_id' => $requestId,
                'status' => 'DELIVERED',
                'configuration_sha256' => $attempt['configuration_sha256'],
                'provider_message_sha256' => hash('sha256', $messageId),
                'delivered_at' => gmdate('c'),
                'replayed' => false,
            ];
            $email['delivery_status'] = 'TEST_DELIVERY_VERIFIED';
            $email['deliveries'][$requestId] = $receipt;
            return $receipt;
        });
        AuditLogger::info('owner_setup.email.delivery_verified', ['actor_id' => Auth::id(), 'request_id' => $requestId]);
        return $receipt;
    }

    /** Resolve an interrupted delivery only after checking the provider's durable record. */
    public function reconcileEmailDelivery(string $requestId, string $outcome, string $providerMessageId = ''): array
    {
        $requestId = $this->requestId($requestId);
        if (!in_array($outcome, ['DELIVERED', 'NOT_DELIVERED'], true)) {
            throw new \InvalidArgumentException('Email reconciliation outcome is invalid.');
        }
        $receipt = $this->mutate(function (array &$config) use ($requestId, $outcome, $providerMessageId): array {
            $email =& $config['email'];
            $existing = $email['deliveries'][$requestId] ?? null;
            if (!in_array($existing['status'] ?? '', ['DELIVERY_PENDING', 'DELIVERY_OUTCOME_UNKNOWN_NO_RETRY'], true)) {
                throw new \RuntimeException('Email delivery is not awaiting reconciliation.');
            }
            $messageId = trim($providerMessageId);
            if ($outcome === 'DELIVERED' && ($messageId === '' || strlen($messageId) > 190)) {
                throw new \InvalidArgumentException('Provider message identity is required for delivered reconciliation.');
            }
            $receipt = [
                'request_id' => $requestId,
                'status' => $outcome === 'DELIVERED' ? 'DELIVERED_RECONCILED' : 'NOT_DELIVERED_RECONCILED',
                'configuration_sha256' => (string) ($existing['configuration_sha256'] ?? ''),
                'reconciled_at' => gmdate('c'),
                'replayed' => false,
            ];
            if ($messageId !== '') $receipt['provider_message_sha256'] = hash('sha256', $messageId);
            $email['deliveries'][$requestId] = $receipt;
            $email['delivery_status'] = $outcome === 'DELIVERED' ? 'TEST_DELIVERY_VERIFIED' : 'NOT_TESTED';
            return $receipt;
        });
        AuditLogger::info('owner_setup.email.delivery_reconciled', ['actor_id' => Auth::id(), 'request_id' => $requestId, 'outcome' => $outcome]);
        return $receipt;
    }

    /** Verify and durably deduplicate one Stripe test-mode webhook event. */
    public function verifyStripeTestWebhook(string $eventId, string $timestamp, string $payload, string $signature): array
    {
        $eventId = $this->requestId($eventId);
        $event = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($event) || ($event['id'] ?? null) !== $eventId || ($event['livemode'] ?? null) !== false) {
            throw new \RuntimeException('Stripe test webhook payload identity or mode is invalid.');
        }
        $receipt = $this->mutate(function (array &$config) use ($eventId, $timestamp, $payload, $signature): array {
            $stripe =& $config['stripe'];
            if (empty($stripe['test_secret']) || empty($stripe['test_webhook_secret'])) {
                throw new \RuntimeException('Stripe test credentials and webhook secret are required.');
            }
            $payloadHash = hash('sha256', $payload);
            $secret = Crypto::decrypt((string) $stripe['test_webhook_secret']);
            if (!WebhookSigner::verify($secret, $timestamp, $payload, $signature)) {
                throw new \RuntimeException('Stripe test webhook signature is invalid or stale.');
            }
            if (isset($stripe['test_webhook_receipts'][$eventId])) {
                $existing = $stripe['test_webhook_receipts'][$eventId];
                if (!hash_equals((string) $existing['payload_sha256'], $payloadHash)) {
                    throw new \RuntimeException('Stripe event identity was reused with a different payload.');
                }
                return array_merge($existing, ['replayed' => true]);
            }
            $receipt = ['request_id' => $eventId, 'status' => 'TEST_WEBHOOK_VERIFIED',
                'payload_sha256' => $payloadHash, 'verified_at' => gmdate('c'), 'replayed' => false];
            $stripe['test_webhook_receipts'][$eventId] = $receipt;
            return $receipt;
        });
        if (!$receipt['replayed']) AuditLogger::info('owner_setup.stripe.test_webhook_verified', ['actor_id' => Auth::id(), 'request_id' => $eventId]);
        return $receipt;
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

    private function hostingState(array $hosting): string
    {
        if (empty($hosting['provider'])) return 'HOSTING_NOT_CONFIGURED';
        if (empty($hosting['deploy_credential'])) return 'CREDENTIALS_REQUIRED';
        foreach ($hosting['verifications'] ?? [] as $receipt) {
            if (($receipt['status'] ?? '') === 'HOSTING_COMPATIBILITY_VERIFIED') return 'HOSTING_COMPATIBILITY_VERIFIED';
        }
        return 'HOSTING_CONFIGURED_NOT_VERIFIED';
    }

    private function emailState(array $email): string
    {
        if (empty($email['provider'])) return 'EMAIL_NOT_CONFIGURED';
        if (empty($email['provider_credential'])) return 'EMAIL_CREDENTIALS_REQUIRED';
        if (($email['delivery_status'] ?? '') === 'TEST_DELIVERY_VERIFIED') return 'EMAIL_TEST_DELIVERY_VERIFIED';
        return 'EMAIL_CONFIGURED_NOT_TESTED';
    }

    private function stripeTestState(array $stripe): string
    {
        if (!empty($stripe['test_webhook_receipts'])) return 'TEST_WEBHOOK_VERIFIED_API_NOT_VERIFIED';
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

    private function configurationHash(array $configuration): string
    {
        ksort($configuration);
        return hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function requestId(string $value): string
    {
        $value = trim($value);
        if (!preg_match('/\A[A-Za-z0-9._:-]{8,100}\z/D', $value)) {
            throw new \InvalidArgumentException('Verification request identity is invalid.');
        }
        return $value;
    }

    private function lastReceipt(array $receipts): ?array
    {
        if ($receipts === []) return null;
        $receipt = end($receipts);
        return is_array($receipt) ? $receipt : null;
    }

    private function mutate(callable $callback): array
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $statement = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ? FOR UPDATE');
            $statement->execute([self::STORAGE_KEY]);
            $encoded = (string) $statement->fetchColumn();
            $config = $encoded === '' ? [] : json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($config)) throw new \RuntimeException('Owner setup configuration is invalid.');
            $result = $callback($config);
            Setting::set(self::STORAGE_KEY, json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            $pdo->commit();
            return $result;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $exception;
        }
    }
}
