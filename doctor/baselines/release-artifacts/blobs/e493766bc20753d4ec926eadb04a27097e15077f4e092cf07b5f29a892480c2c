<?php

declare(strict_types=1);

namespace SysAdmin\Doctor\engine\Support;

abstract readonly class ArrayValue implements \JsonSerializable
{
    /** @param array<string, mixed> $data */
    public function __construct(protected array $data)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->normalize($this->data);
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private function normalize(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof \JsonSerializable) {
                $data[$key] = $value->jsonSerialize();
            } elseif (is_array($value)) {
                $data[$key] = $this->normalize($value);
            } elseif ($value instanceof \BackedEnum) {
                $data[$key] = $value->value;
            }
        }
        return $data;
    }
}
