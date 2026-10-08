<?php

declare(strict_types=1);

namespace App\Tests\Application\Job;

use App\Application\Job\Ledger;
use App\Domain\Run\RunType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

#[CoversClass(Ledger::class)]
final class LedgerKeyTest extends TestCase
{
    public function testIdempotencyKeyIsDeterministicPerRunTicketAndAction(): void
    {
        $run = Uuid::v7()->toRfc4122();

        $a = Ledger::idempotencyKey($run, 20318, RunType::Transfer);
        $b = Ledger::idempotencyKey($run, 20318, RunType::Transfer);
        $otherTicket = Ledger::idempotencyKey($run, 20319, RunType::Transfer);
        $otherAction = Ledger::idempotencyKey($run, 20318, RunType::Reprice);
        $otherRun = Ledger::idempotencyKey(Uuid::v7()->toRfc4122(), 20318, RunType::Transfer);

        self::assertSame($a, $b, 'a retry must send the same key to the platform');
        self::assertNotSame($a, $otherTicket);
        self::assertNotSame($a, $otherAction);
        self::assertNotSame($a, $otherRun);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $a);
    }
}
