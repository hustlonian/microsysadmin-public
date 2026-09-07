<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Registry;

use SysAdmin\Doctor\engine\Context\DoctorContext;
use SysAdmin\Doctor\engine\Context\EnvironmentContext;
use SysAdmin\Doctor\engine\Contracts\CheckInterface;
use SysAdmin\Doctor\engine\Detection\ProbeExecutor;
use SysAdmin\Doctor\engine\Support\CheckStatus;
use SysAdmin\Doctor\engine\Support\Value\CheckMetadata;
use SysAdmin\Doctor\engine\Support\Value\CheckResult;
use SysAdmin\Doctor\engine\Support\Value\Evidence;

final readonly class DefinitionCheck implements CheckInterface
{
    public function __construct(private array $definition, private ProbeExecutor $probes = new ProbeExecutor())
    {
    }

    public function metadata(): CheckMetadata { return new CheckMetadata((array) $this->definition['metadata']); }

    public function supports(EnvironmentContext $environment): bool
    {
        foreach ((array) ($this->definition['requires_commands'] ?? []) as $command) {
            if ($environment->command((string) $command) === null) {
                return false;
            }
        }
        $requires = (array) ($this->definition['supports'] ?? []);
        foreach ($requires as $key => $allowed) {
            if (!in_array($environment->get((string) $key), (array) $allowed, true)) {
                return false;
            }
        }
        return $environment->supportsModule($this->metadata()->module());
    }

    public function run(DoctorContext $context): CheckResult
    {
        $started = microtime(true);
        if (!$this->supports($context->environment)) {
            return new CheckResult($this->metadata(), CheckStatus::SKIPPED, 'Check is unsupported in this environment.', [], 0, gmdate(DATE_ATOM));
        }
        $probe = $this->probes->run((array) ($this->definition['probe'] ?? []), $context);
        $status = (bool) $probe->get('skipped', false)
            ? CheckStatus::SKIPPED
            : ($probe->healthy()
            ? CheckStatus::PASS
            : CheckStatus::from((string) ($this->definition['failure_status'] ?? 'FAIL')));
        $evidence = Evidence::observation(
            (string) $probe->get('type'),
            (string) $probe->get('message'),
            $probe->get('expected'),
            $probe->get('actual'),
            (array) $probe->get('details', [])
        );
        return new CheckResult(
            $this->metadata(), $status, (string) $probe->get('message'), [$evidence],
            round((microtime(true) - $started) * 1000, 3), gmdate(DATE_ATOM)
        );
    }
}
