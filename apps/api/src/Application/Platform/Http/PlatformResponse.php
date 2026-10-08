<?php

declare(strict_types=1);

namespace App\Application\Platform\Http;

use App\Application\Platform\PlatformApiException;
use App\Application\Platform\PlatformFailure;

/** A 2xx vendor response: decoded body plus how long it took. The accessors turn a missing field into a Rejected failure. */
final readonly class PlatformResponse
{
    /** @param array<string, mixed> $body */
    public function __construct(
        public array $body,
        public int $latencyMs,
        private string $platform,
    ) {
    }

    /** @throws PlatformApiException */
    public function str(string $key): string
    {
        $v = $this->body[$key] ?? null;

        return \is_string($v) || \is_int($v) ? (string) $v : throw $this->missing($key);
    }

    /** @throws PlatformApiException */
    public function int(string $key): int
    {
        $v = $this->body[$key] ?? null;

        return is_numeric($v) ? (int) $v : throw $this->missing($key);
    }

    /** The same response narrowed to a nested object, for vendors that wrap their payload. */
    public function nested(string $key): self
    {
        $v = $this->body[$key] ?? null;
        /** @var array<string, mixed> $inner */
        $inner = \is_array($v) ? $v : [];

        return new self($inner, $this->latencyMs, $this->platform);
    }

    private function missing(string $key): PlatformApiException
    {
        return new PlatformApiException(PlatformFailure::Rejected, $this->platform . ": response missing \"{$key}\"");
    }
}
