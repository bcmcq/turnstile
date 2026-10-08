<?php

declare(strict_types=1);

namespace App\Tests\Application\Arena;

use App\Application\Arena\ArenaGeometry;
use App\Domain\Venue\SectionTier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArenaGeometry::class)]
final class ArenaGeometryTest extends TestCase
{
    public function testBuildsAtLeastTheTargetNumberOfSeatsInThirtyThreeSections(): void
    {
        $arena = new ArenaGeometry(100_000);

        self::assertGreaterThanOrEqual(100_000, \count($arena->seats));
        self::assertLessThan(102_000, \count($arena->seats));
        self::assertCount(33, $arena->sections);
        self::assertSame('FLR', $arena->sections[0]->code);
        self::assertSame('101', $arena->sections[1]->code);
        self::assertSame('216', $arena->sections[32]->code);
    }

    public function testEverySeatIsInsideTheCanvasAndOutsideTheFloorUnlessItIsAFloorSeat(): void
    {
        $arena = new ArenaGeometry(100_000);
        $cx = ArenaGeometry::WIDTH / 2;
        $cy = ArenaGeometry::HEIGHT / 2;

        foreach ($arena->seats as $seat) {
            self::assertGreaterThan(0, $seat->x);
            self::assertLessThan(ArenaGeometry::WIDTH, $seat->x);
            self::assertGreaterThan(0, $seat->y);
            self::assertLessThan(ArenaGeometry::HEIGHT, $seat->y);
            if (0 !== $seat->sectionIndex) {
                $insideFloor = abs($seat->x - $cx) < 150 && abs($seat->y - $cy) < 70;
                self::assertFalse($insideFloor, "seat {$seat->rowLabel}-{$seat->seatNumber} of section {$seat->sectionIndex} overlaps the floor");
            }
        }
    }

    public function testSeatNumbersAreUniqueWithinARowOfASection(): void
    {
        $arena = new ArenaGeometry(100_000);
        $seen = [];
        foreach ($arena->seats as $seat) {
            $key = $seat->sectionIndex . '|' . $seat->rowLabel . '|' . $seat->seatNumber;
            self::assertArrayNotHasKey($key, $seen, "duplicate seat {$key}");
            $seen[$key] = true;
        }
    }

    public function testUpperSectionsSitOutsideLowerSections(): void
    {
        $arena = new ArenaGeometry(100_000);
        $lower = $arena->sections[1];
        $upper = $arena->sections[17];

        self::assertSame(SectionTier::Lower, $lower->tier);
        self::assertSame(SectionTier::Upper, $upper->tier);
        self::assertSame($lower->angleStart, $upper->angleStart);
        self::assertGreaterThan($lower->ringEnd, $upper->ringStart);
    }

    public function testRowLabels(): void
    {
        self::assertSame('A', ArenaGeometry::rowLabel(1));
        self::assertSame('Z', ArenaGeometry::rowLabel(26));
        self::assertSame('AA', ArenaGeometry::rowLabel(27));
        self::assertSame('BA', ArenaGeometry::rowLabel(53));
    }
}
