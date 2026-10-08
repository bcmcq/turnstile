<?php

declare(strict_types=1);

namespace App\Application\Arena;

use App\Domain\Venue\ArenaSize;
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

    private const float FLOOR_W = 300.0;
    private const float FLOOR_H = 140.0;
    private const float CORNER = 12.0;

    /** @var list<SeatPoint> */
    public private(set) array $seats = [];

    /** @var list<SectionShape> sectors 0..15 lower, 16..31 upper */
    public private(set) array $sections = [];

    /** Defaults are the Figma dot field (4,280 seats); for() holds the presets. */
    public function __construct(
        public readonly float $seatPitch = 5.4,     // along a ring
        public readonly float $rowPitch = 7.0,      // between rings
        public readonly int $lowerRings = 8,
        public readonly int $upperRings = 10,
        private readonly float $firstGap = 16.0,    // floor edge → first ring
        private readonly float $concourse = 26.0,   // extra gap between the bowls
        private readonly float $aisleHalf = 4.0,    // half width of the aisle between sections
        private readonly float $midAisleHalf = 2.0, // half width of the aisle through a section's middle
    ) {
        $this->build();
    }

    public static function for(ArenaSize $size): self
    {
        return match ($size) {
            ArenaSize::Demo => new self(seatPitch: $size->seatPitch()),
            // about 49k seats on the same canvas: a dense dot field at fit, individual seats only when zoomed
            ArenaSize::Full => new self(seatPitch: $size->seatPitch(), rowPitch: 2.3, lowerRings: 24, upperRings: 38, firstGap: 12.0, concourse: 20.0, aisleHalf: 2.0, midAisleHalf: 1.0),
        };
    }

    private function build(): void
    {
        $rings = $this->lowerRings + $this->upperRings;
        $counters = [];
        for ($ring = 0; $ring < $rings; ++$ring) {
            $isUpper = $ring >= $this->lowerRings;
            $ringInTier = $isUpper ? $ring - $this->lowerRings : $ring;
            $lastRingOfTier = $ringInTier === ($isUpper ? $this->upperRings : $this->lowerRings) - 1;
            foreach ($this->ringSeats($this->ringOffset($ring)) as [$sector, $x, $y]) {
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
        $lowerLabelOff = $this->ringOffset($this->lowerRings - 1) + $this->rowPitch + $this->concourse / 2;
        $upperLabelOff = $this->ringOffset($rings - 1) + 16;
        foreach ([SectionTier::Lower, SectionTier::Upper] as $tier) {
            $isUpper = SectionTier::Upper === $tier;
            $outer = $this->ringOffset($isUpper ? $rings - 1 : $this->lowerRings - 1);
            for ($k = 0; $k < self::SECTORS; ++$k) {
                [$lx, $ly] = $this->sectionPoint($k, 0.5, $isUpper ? $upperLabelOff : $lowerLabelOff);
                [$sx, $sy] = $this->sectionPoint($k, 0.0, $outer);
                [$ex, $ey] = $this->sectionPoint($k, 1.0, $outer);
                $this->sections[] = new SectionShape(
                    (string) ($tier->codeBase() + $k + 1),
                    $tier,
                    self::angleOf($sx, $sy),
                    self::angleOf($ex, $ey),
                    $isUpper ? $this->lowerRings : 0,
                    $isUpper ? $rings - 1 : $this->lowerRings - 1,
                    self::WIDTH / 2 + $lx,
                    self::HEIGHT / 2 + $ly,
                );
            }
        }
    }

    private function ringOffset(int $ring): float
    {
        return $this->firstGap + $ring * $this->rowPitch + ($ring >= $this->lowerRings ? $this->concourse : 0);
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
        $n = (int) floor($len / $this->seatPitch);
        $sectionLen = $len / $parts;
        for ($i = 0; $i < $n; ++$i) {
            $t = $len / 2 + ($i - ($n - 1) / 2) * $this->seatPitch;
            $part = min($parts - 1, (int) floor($t / $sectionLen));
            $local = $t - $part * $sectionLen;
            if ($local < $this->aisleHalf || $sectionLen - $local < $this->aisleHalf || abs($local - $sectionLen / 2) < $this->midAisleHalf) {
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
        $n = (int) round($arc / $this->seatPitch);
        for ($k = 0; $k < $n; ++$k) {
            $i = $reverse ? $n - 1 - $k : $k;
            $s = ($i + 0.5) / $n * $arc;
            if ($s < $this->aisleHalf || $arc - $s < $this->aisleHalf || abs($s - $arc / 2) < $this->midAisleHalf) {
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
