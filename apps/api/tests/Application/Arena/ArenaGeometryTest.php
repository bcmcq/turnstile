<?php

declare(strict_types=1);

namespace App\Tests\Application\Arena;

use App\Application\Arena\ArenaGeometry;
use App\Domain\Venue\ArenaSize;
use App\Domain\Venue\SectionTier;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArenaGeometry::class)]
final class ArenaGeometryTest extends TestCase
{
    public function testBuildsAFewThousandSeatsInThirtyTwoSections(): void
    {
        $arena = new ArenaGeometry();

        self::assertGreaterThan(3_000, \count($arena->seats));
        self::assertLessThan(6_000, \count($arena->seats));
        self::assertCount(32, $arena->sections);
        self::assertSame('101', $arena->sections[0]->code);
        self::assertSame('216', $arena->sections[31]->code);
    }

    public function testFullSizeIsAFiftyThousandSeatCrowd(): void
    {
        $arena = ArenaGeometry::for(ArenaSize::Full);

        self::assertGreaterThan(45_000, \count($arena->seats));
        self::assertLessThan(55_000, \count($arena->seats));
        self::assertCount(32, $arena->sections);
    }

    #[DataProvider('sizes')]
    public function testEverySeatIsInsideTheCanvasAndOutsideTheFloor(ArenaSize $size): void
    {
        $arena = ArenaGeometry::for($size);
        $cx = ArenaGeometry::WIDTH / 2;
        $cy = ArenaGeometry::HEIGHT / 2;

        foreach ($arena->seats as $seat) {
            self::assertGreaterThan(0, $seat->x);
            self::assertLessThan(ArenaGeometry::WIDTH, $seat->x);
            self::assertGreaterThan(0, $seat->y);
            self::assertLessThan(ArenaGeometry::HEIGHT, $seat->y);
            $insideFloor = abs($seat->x - $cx) < 150 && abs($seat->y - $cy) < 70;
            self::assertFalse($insideFloor, "seat {$seat->rowLabel}-{$seat->seatNumber} of section {$seat->sectionIndex} overlaps the floor");
        }
    }

    public function testSeatNumbersAreUniqueWithinARowOfASection(): void
    {
        $arena = new ArenaGeometry();
        $seen = [];
        foreach ($arena->seats as $seat) {
            $key = $seat->sectionIndex . '|' . $seat->rowLabel . '|' . $seat->seatNumber;
            self::assertArrayNotHasKey($key, $seen, "duplicate seat {$key}");
            $seen[$key] = true;
        }
    }

    public function testUpperSectionsSitOutsideLowerSections(): void
    {
        $arena = new ArenaGeometry();
        $lower = $arena->sections[0];
        $upper = $arena->sections[16];

        self::assertSame(SectionTier::Lower, $lower->tier);
        self::assertSame(SectionTier::Upper, $upper->tier);
        self::assertSame($lower->code, '101');
        self::assertSame($upper->code, '201');
        self::assertGreaterThan($lower->ringEnd, $upper->ringStart);
    }

    /** @return iterable<string, array{ArenaSize}> */
    public static function sizes(): iterable
    {
        foreach (ArenaSize::cases() as $size) {
            yield $size->value => [$size];
        }
    }

    public function testRowLabels(): void
    {
        self::assertSame('A', ArenaGeometry::rowLabel(1));
        self::assertSame('Z', ArenaGeometry::rowLabel(26));
        self::assertSame('AA', ArenaGeometry::rowLabel(27));
        self::assertSame('BA', ArenaGeometry::rowLabel(53));
    }
}
