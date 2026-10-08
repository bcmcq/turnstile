<?php

declare(strict_types=1);

namespace App\Command;

use App\Application\Realtime\Publisher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'turnstile:publish', description: 'Fold worker events from Redis into Mercure batches (runs forever; one instance)')]
final class PublishCommand
{
    public function __construct(private readonly Publisher $publisher)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Option(description: 'Stop after N ticks (tests)')] ?int $ticks = null): int
    {
        pcntl_async_signals(true);
        pcntl_signal(\SIGTERM, $this->publisher->stop(...));
        pcntl_signal(\SIGINT, $this->publisher->stop(...));
        $io->info('publisher started: 250 ms ticks on turnstile/{metrics,seats,log,run}');
        $this->publisher->run($ticks);
        $io->text('publisher stopped');

        return Command::SUCCESS;
    }
}
