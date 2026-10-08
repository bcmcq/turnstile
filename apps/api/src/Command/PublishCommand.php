<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

// ponytail: phase 0 stub. Publishes a heartbeat so the Mercure path is proven end to end.
// The real loop (XREADGROUP → fold → publish every 250 ms) lands in phase 3.
#[AsCommand(name: 'turnstile:publish', description: 'Fold worker events from Redis into Mercure batches')]
final class PublishCommand
{
    public function __construct(private readonly HubInterface $hub) {}

    public function __invoke(SymfonyStyle $io): int
    {
        $io->info('publisher started (stub): heartbeat every 2s on turnstile/health');

        while (true) {
            $this->hub->publish(new Update('turnstile/health', json_encode(['t' => microtime(true)], JSON_THROW_ON_ERROR)));
            sleep(2);
        }
    }
}
