<?php

declare(strict_types=1);

namespace SysAdmin\Contracts;

interface TerminalSessionInterface
{
    public function startTerminal(string $language, string $code = '', bool $confirmed = false, int $cols = 100, int $rows = 30): array;
    public function terminalOutput(string $id, int $offset = 0): array;
    public function terminalInput(string $id, string $input): array;
    public function stopTerminal(string $id): array;
}
