<?php

declare(strict_types=1);

namespace App\Application\Job;

use App\Application\Platform\ClientRateLimiter;
use App\Application\Platform\PlatformClientRegistry;
use App\Application\Platform\PlatformRepository;
use App\Application\Platform\PlatformRow;
use App\Application\Run\RunRow;
use App\Domain\Platform\PlatformCode;

/** Everything an action applier needs, resolved once per attempt. */
final readonly class JobContext
{
    public function __construct(
        public RunRow $run,
        public TicketRow $ticket,
        public string $idempotencyKey,
        public PlatformClientRegistry $clients,
        public PlatformRepository $platforms,
        public ClientRateLimiter $limiter,
    ) {
    }

    public function currentPlatform(): ?PlatformRow
    {
        return null === $this->ticket->platformId ? null : $this->platforms->byId($this->ticket->platformId);
    }

    public function targetPlatform(): PlatformRow
    {
        return $this->platforms->byId($this->run->targetPlatformId ?? throw new \LogicException('Run has no target platform'));
    }

    public function clientFor(PlatformCode $code): \App\Application\Platform\PlatformClientInterface
    {
        return $this->clients->get($code);
    }
}
