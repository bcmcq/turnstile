<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Arena\ArenaSeeder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'turnstile:seed', description: 'Build the arena: venue, sections, seats and tickets, one event, three platforms')]
final class SeedCommand
{
    public function __construct(private readonly ArenaSeeder $seeder)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Do nothing when the arena already exists')]
        bool $ifEmpty = false,
        #[Option(description: 'Truncate everything first')]
        bool $fresh = false,
    ): int {
        if ($fresh) {
            $this->seeder->truncate();
            $io->text('truncated');
        } elseif ($this->seeder->isSeeded()) {
            if ($ifEmpty) {
                $io->text('arena already seeded, skipping');

                return Command::SUCCESS;
            }
            $io->error('arena already seeded; pass --fresh to rebuild');

            return Command::FAILURE;
        }

        $start = hrtime(true);
        $result = $this->seeder->seed();
        $ms = (hrtime(true) - $start) / 1e6;
        $io->success(\sprintf('%s seats in %d sections seeded in %.1fs', number_format($result['seats']), $result['sections'], $ms / 1000));

        return Command::SUCCESS;
    }
}
