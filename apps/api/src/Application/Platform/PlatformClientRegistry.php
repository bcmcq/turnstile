<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Domain\Platform\PlatformCode;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class PlatformClientRegistry
{
    /** @var array<string, PlatformClientInterface> */
    private array $byCode = [];

    /** @param iterable<PlatformClientInterface> $clients */
    public function __construct(#[AutowireIterator('app.platform_client')] iterable $clients)
    {
        foreach ($clients as $client) {
            $this->byCode[$client->code()->value] = $client;
        }
    }

    public function get(PlatformCode $code): PlatformClientInterface
    {
        return $this->byCode[$code->value] ?? throw new \LogicException(\sprintf('No client registered for platform "%s"', $code->value));
    }
}
