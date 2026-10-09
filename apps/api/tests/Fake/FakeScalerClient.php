<?php

declare(strict_types=1);

namespace App\Tests\Fake;

use App\Application\Scaling\ScalerClientInterface;
use App\Application\Scaling\ScaleResult;

/** Records scale targets instead of cloning containers; wired in place of HttpScalerClient under when@test. */
final class FakeScalerClient implements ScalerClientInterface
{
    /** @var list<int> */
    public private(set) array $targets = [];
    public int $running = 2;

    #[\Override]
    public function scale(int $workers): ScaleResult
    {
        $this->targets[] = $workers;

        return new ScaleResult($workers, $this->running, []);
    }

    /** @return list<string> */
    #[\Override]
    public function running(): array
    {
        return [];
    }
}
