<?php

declare(strict_types=1);

namespace App\Application\Arena;

use App\Domain\Venue\SeatType;
use App\Domain\Venue\SectionTier;

/**
 * Lays out the arena the way the Figma frames do: a rounded-rectangle floor with no seats, then concentric
 * rounded-rectangle rings of seats, a concourse gap between the lower and upper bowl. Sections are cut by
 * position along the ring, not by angle from the centre: each long edge holds four sections, each short edge
 * two, and each corner arc is a section of its own, so aisles run perpendicular to the rows and line up from
 * ring to ring. Deterministic, so every machine gets the same map.
 *
 * Logical canvas is WIDTH × HEIGHT units; the browser scales it to the panel and zooms into it.
 */
final class ArenaGeometry
{
    public const float WIDTH = 720.0;
    public const float HEIGHT = 540.0;
    public const int SECTORS = 16;
    public const int LOWER_RINGS = 8;
    public const int UPPER_RINGS = 10;

    private const float FLOOR_W = 300.0;
    private const float FLOOR_H = 140.0;
    private const float CORNER = 12.0;
    private const float SEAT_PITCH = 5.4;     // along a ring
    private const float ROW_PITCH = 7.0;      // between rings
    private const float FIRST_GAP = 16.0;     // floor edge → first ring
    private const float CONCOURSE = 26.0;     // extra gap between the bowls
    private const float AISLE_HALF = 4.0;     // half width of the aisle between sections
    private const float MID_AISLE_HALF = 2.0; // half width of the aisle through a section's middle

    /** @var list<SeatPoint> */
    public private(set) array $seats = [];

    /** @var list<SectionShape> sectors 0..15 lower, 16..31 upper */
    public private(set) array $sections = [];

    public function __construct()
    {
        $this->build();
    }

    private function build(): void
    {
        $rings = self::LOWER_RINGS + self::UPPER_RINGS;
        $counters = [];
        for ($ring = 0; $ring < $rings; ++$ring) {
            $isUpper = $ring >= self::LOWER_RINGS;
            $ringInTier = $isUpper ? $ring - self::LOWER_RINGS : $ring;
            $lastRingOfTier = $ringInTier === ($isUpper ? self::UPPER_RINGS : self::LOWER_RINGS) - 1;
            foreach ($this->ringSeats(self::ringOffset($ring)) as [$sector, $x, $y]) {
                $sectionIndex = ($isUpper ? self::SECTORS : 0) + $sector;
                $seatNo = ($counters[$sectionIndex][$ring] ?? 0) + 1;
                $counters[$sectionIndex][$ring] = $seatNo;
                $type = match (true) {
                    !$isUpper && $ringInTier < 2 => SeatType::Premium,
                    $lastRingOfTier && 0 === $seatNo % 20 => SeatType::Ada,
                    default => SeatType::Standard,
                };
                $this->seats[] = new SeatPoint($sectionIndex, $ring, self::rowLabel($ringInTier + 1), $seatNo, $type, self::WIDTH / 2 + $x, self::HEIGHT / 2 + $y);
            }
        }

        // Lower labels sit in the concourse, upper labels outside the bowl, each on its section's centre line.
        $lowerLabelOff = self::ringOffset(self::LOWER_RINGS - 1) + self::ROW_PITCH + self::CONCOURSE / 2;
        $upperLabelOff = self::ringOffset($rings - 1) + 16;
        foreach ([SectionTier::Lower, SectionTier::Upper] as $tier) {
            $isUpper = SectionTier::Upper === $tier;
            $outer = self::ringOffset($isUpper ? $rings - 1 : self::LOWER_RINGS - 1);
            for ($k = 0; $k < self::SECTORS; ++$k) {
                [$lx, $ly] = $this->sectionPoint($k, 0.5, $isUpper ? $upperLabelOff : $lowerLabelOff);
                [$sx, $sy] = $this->sectionPoint($k, 0.0, $outer);
                [$ex, $ey] = $this->sectionPoint($k, 1.0, $outer);
                $this->sections[] = new SectionShape(
                    (string) ($tier->codeBase() + $k + 1),
                    $tier,
                    self::angleOf($sx, $sy),
                    self::angleOf($ex, $ey),
                    $isUpper ? self::LOWER_RINGS : 0,
                    $isUpper ? $rings - 1 : self::LOWER_RINGS - 1,
                    self::WIDTH / 2 + $lx,
                    self::HEIGHT / 2 + $ly,
                );
            }
        }
    }

    private static function ringOffset(int $ring): float
    {
        return self::FIRST_GAP + $ring * self::ROW_PITCH + ($ring >= self::LOWER_RINGS ? self::CONCOURSE : 0);
    }

    /** @return array{float, float, float} half-width, half-height, corner radius of the ring at $off */
    private function ringGeometry(float $off): array
    {
        return [self::FLOOR_W / 2 + $off, self::FLOOR_H / 2 + $off, self::CORNER + $off];
    }

    /**
     * Seats on one ring as [sector, x, y] relative to the arena centre. Straight edges are stepped from their
     * midpoint so every ring shares the same columns; corner arcs get as many evenly spaced seats as fit.
     * Aisles: a gap at every section boundary and a narrower one through each section's middle.
     *
     * @return iterable<array{int, float, float}>
     */
    private function ringSeats(float $off): iterable
    {
        [$a, $b, $cr] = $this->ringGeometry($off);
        $edgeW = 2 * ($a - $cr);   // top / bottom straight length (same on every ring)
        $edgeH = 2 * ($b - $cr);   // left / right straight length

        // One continuous clockwise walk per ring (ids, and so dispatch order, follow it):
        // top → top-right corner → right → bottom-right → bottom → bottom-left → left → top-left.
        yield from $this->edgeSeats('top', $edgeW, 4, static fn (float $t): array => [-$edgeW / 2 + $t, -$b]);
        yield from $this->cornerSeats($a, $b, $cr, 1, -1, 14, true);
        yield from $this->edgeSeats('right', $edgeH, 2, static fn (float $t): array => [$a, -$edgeH / 2 + $t]);
        yield from $this->cornerSeats($a, $b, $cr, 1, 1, 1, false);
        yield from $this->edgeSeats('bottom', $edgeW, 4, static fn (float $t): array => [$edgeW / 2 - $t, $b]);
        yield from $this->cornerSeats($a, $b, $cr, -1, 1, 6, true);
        yield from $this->edgeSeats('left', $edgeH, 2, static fn (float $t): array => [-$a, $edgeH / 2 - $t]);
        yield from $this->cornerSeats($a, $b, $cr, -1, -1, 9, false);
    }

    /**
     * Straight edge: t runs along the edge from its start; sections are equal slices with an aisle at each
     * boundary and a narrower one through the middle.
     *
     * @param \Closure(float): array{float, float} $point
     *
     * @return iterable<array{int, float, float}>
     */
    private function edgeSeats(string $edge, float $len, int $parts, \Closure $point): iterable
    {
        $n = (int) floor($len / self::SEAT_PITCH);
        $sectionLen = $len / $parts;
        for ($i = 0; $i < $n; ++$i) {
            $t = $len / 2 + ($i - ($n - 1) / 2) * self::SEAT_PITCH;
            $part = min($parts - 1, (int) floor($t / $sectionLen));
            $local = $t - $part * $sectionLen;
            if ($local < self::AISLE_HALF || $sectionLen - $local < self::AISLE_HALF || abs($local - $sectionLen / 2) < self::MID_AISLE_HALF) {
                continue;
            }
            [$x, $y] = $point($t);
            yield [self::edgeSector($edge, $part, $len, $t), $x, $y];
        }
    }

    /**
     * Corner arc: one section, seats fanned from the arc centre; $reverse walks it the other way so the
     * ring stays clockwise.
     *
     * @return iterable<array{int, float, float}>
     */
    private function cornerSeats(float $a, float $b, float $cr, int $sx, int $sy, int $sector, bool $reverse): iterable
    {
        $arc = \M_PI * $cr / 2;
        $n = (int) round($arc / self::SEAT_PITCH);
        for ($k = 0; $k < $n; ++$k) {
            $i = $reverse ? $n - 1 - $k : $k;
            $s = ($i + 0.5) / $n * $arc;
            if ($s < self::AISLE_HALF || $arc - $s < self::AISLE_HALF || abs($s - $arc / 2) < self::MID_AISLE_HALF) {
                continue;
            }
            $ang = $s / $arc * \M_PI / 2;
            yield [$sector, $sx * ($a - $cr + $cr * cos($ang)), $sy * ($b - $cr + $cr * sin($ang))];
        }
    }

    /** Sector index (0..15) of a straight-edge section; edges are walked clockwise so numbering follows the design. */
    private static function edgeSector(string $edge, int $part, float $len, float $t): int
    {
        return match ($edge) {
            'right' => $t < $len / 2 ? 15 : 0,      // 116 above centre, 101 below
            'bottom' => 2 + $part,                  // 103..106 right → left
            'left' => $t < $len / 2 ? 7 : 8,        // 108 below centre, 109 above
            default => 10 + $part,                  // 111..114 left → right
        };
    }

    /**
     * Point on the ring at $off, at fraction $u (0..1) along sector $k's span.
     *
     * @return array{float, float}
     */
    private function sectionPoint(int $k, float $u, float $off): array
    {
        [$a, $b, $cr] = $this->ringGeometry($off);
        $edgeW = 2 * ($a - $cr);
        $edgeH = 2 * ($b - $cr);
        $corner = static fn (int $sx, int $sy, float $u): array => [
            $sx * ($a - $cr + $cr * cos($u * \M_PI / 2)),
            $sy * ($b - $cr + $cr * sin($u * \M_PI / 2)),
        ];

        return match (true) {
            0 === $k => [$a, $u * $edgeH / 2],
            1 === $k => $corner(1, 1, $u),
            $k <= 5 => [$edgeW / 2 - ($k - 2 + $u) * $edgeW / 4, $b],
            6 === $k => $corner(-1, 1, $u),
            7 === $k => [-$a, $edgeH / 2 * (1 - $u)],
            8 === $k => [-$a, -$u * $edgeH / 2],
            9 === $k => $corner(-1, -1, $u),
            $k <= 13 => [-$edgeW / 2 + ($k - 10 + $u) * $edgeW / 4, -$b],
            14 === $k => $corner(1, -1, $u),
            default => [$a, -$edgeH / 2 * (1 - $u)],
        };
    }

    private static function angleOf(float $x, float $y): float
    {
        return fmod(atan2($y, $x) + 2 * \M_PI, 2 * \M_PI);
    }

    /** 1 → A, 26 → Z, 27 → AA, 53 → BA … */
    public static function rowLabel(int $n): string
    {
        $label = '';
        while ($n > 0) {
            --$n;
            $label = \chr(65 + $n % 26) . $label;
            $n = intdiv($n, 26);
        }

        return $label;
    }
}
