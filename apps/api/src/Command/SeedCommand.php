<?php

declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

// ponytail: phase 0 stub so the api entrypoint has something to call. Real seed (100k seats) lands in phase 1.
#[AsCommand(name: 'turnstile:seed', description: 'Build the arena: venue, sections, 100,000 seats, one event')]
final class SeedCommand
{
    public function __invoke(SymfonyStyle $io, #[Option(description: 'Skip when the arena already exists')] bool $ifEmpty = false): int
    {
        $io->note('turnstile:seed is a phase 0 stub; nothing seeded yet'.($ifEmpty ? ' (--if-empty)' : ''));

        return Command::SUCCESS;
    }
}
