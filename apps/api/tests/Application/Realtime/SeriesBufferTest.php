<?php

declare(strict_types=1);

namespace App\Tests\Application\Realtime;

use App\Application\Realtime\SeriesBuffer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SeriesBuffer::class)]
final class SeriesBufferTest extends TestCase
{
    public function testKeepsTheLastNSamplesOldestFirstPerMetric(): void
    {
        $buffer = new SeriesBuffer(3);
        foreach ([1, 2, 3, 4] as $v) {
            $buffer->push('jobsPerSec', $v);
        }
        $buffer->push('queued', 9);

        self::assertSame(['jobsPerSec' => [2, 3, 4], 'queued' => [9]], $buffer->all());
    }
}
