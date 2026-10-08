<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Platform\PlatformRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'turnstile:platforms:sync', description: 'Mirror platform chaos/buyer/rate-limit settings from MySQL to Redis for the mocks')]
final class SyncPlatformsCommand
{
    public function __construct(private readonly PlatformRepository $platforms)
    {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $this->platforms->syncToRedis();
        $io->text('platform settings synced to redis');

        return Command::SUCCESS;
    }
}
