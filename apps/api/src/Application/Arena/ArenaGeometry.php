<?php

declare(strict_types=1);

namespace App\Application\Arena;

use App\Domain\Venue\SeatType;
use App\Domain\Venue\SectionTier;

/**
 * Lays out the arena the same way the Figma frames do: a rounded-rectangle floor, then concentric
 * rounded-rectangle rings of seats split into 16 sectors per tier, with radial aisles and a
 * concourse gap between the lower and upper bowl. Deterministic, so every machine gets the same map.
 *
 * Logical canvas is WIDTH × HEIGHT units; the browser scales it to the panel and zooms into it.
 */
final class ArenaGeometry
{
    public const float WIDTH = 880.0;
    public const float HEIGHT = 720.0;
    public const int SECTORS = 16;

    private const float FLOOR_W = 300.0;
    private const float FLOOR_H = 140.0;
    private const float CORNER = 12.0;
    private const float PITCH = 2.0;          // distance between seats along a ring and between rings
    private const float FIRST_GAP = 10.0;     // floor edge → first ring
    private const float CONCOURSE = 8.0;      // extra gap between the bowls
    private const float AISLE_HALF = 1.5;     // half width of a radial aisle
    private const int LOWER_RINGS = 56;
    private const int FLOOR_COLS = 64;
    private const int FLOOR_ROWS = 31;

    /** @var list<SeatPoint> */
    public private(set) array $seats = [];

    /** @var list<SectionShape> */
    public private(set) array $sections = [];

    public private(set) int $upperRings = 0;

    public function __construct(private readonly int $targetSeats = 100_000)
    {
        $this->build();
    }

    private function build(): void
    {
        $cx = self::WIDTH / 2;
        $cy = self::HEIGHT / 2;

        // Floor: section index 0.
        $this->sections[] = new SectionShape('FLR', SectionTier::Floor, 0.0, 0.0, -1, -1, $cx, $cy);
        $fx0 = $cx - self::FLOOR_W / 2 + 8;
        $fy0 = $cy - self::FLOOR_H / 2 + 8;
        $dx = (self::FLOOR_W - 16) / (self::FLOOR_COLS - 1);
        $dy = (self::FLOOR_H - 16) / (self::FLOOR_ROWS - 1);
        for ($r = 0; $r < self::FLOOR_ROWS; ++$r) {
            for ($c = 0; $c < self::FLOOR_COLS; ++$c) {
                $this->seats[] = new SeatPoint(0, $r, self::rowLabel($r + 1), $c + 1, SeatType::Premium, $fx0 + $c * $dx, $fy0 + $r * $dy);
            }
        }

        // Sector shapes: indexes 1..16 lower, 17..32 upper.
        $slice = 2 * \M_PI / self::SECTORS;
        foreach ([SectionTier::Lower, SectionTier::Upper] as $tier) {
            for ($k = 0; $k < self::SECTORS; ++$k) {
                $this->sections[] = new SectionShape(
                    (string) ($tier->codeBase() + $k + 1),
                    $tier,
                    $k * $slice,
                    ($k + 1) * $slice,
                    0,
                    0,
                    0.0,
                    0.0,
                );
            }
        }

        // Rings. Lower bowl is fixed; upper bowl grows until the seat target is met.
        $ring = 0;
        $counters = [];
        while (true) {
            $isUpper = $ring >= self::LOWER_RINGS;
            if ($isUpper && \count($this->seats) >= $this->targetSeats) {
                break;
            }
            $ringInTier = $isUpper ? $ring - self::LOWER_RINGS : $ring;
            $off = self::FIRST_GAP + $ring * self::PITCH + ($isUpper ? self::CONCOURSE : 0);
            [$a, $b, $cr] = $this->ringGeometry($off);
            $perimeter = $this->perimeter($a, $b, $cr);
            $n = (int) round($perimeter / self::PITCH);
            $lastRingOfTier = !$isUpper && $ringInTier === self::LOWER_RINGS - 1;

            for ($i = 0; $i < $n; ++$i) {
                [$x, $y] = $this->pointAt($i / $n * $perimeter, $a, $b, $cr);
                $angle = fmod(atan2($y, $x) + 2 * \M_PI, 2 * \M_PI);
                $boundary = round($angle / $slice) * $slice;
                if (abs($x * sin($boundary) - $y * cos($boundary)) < self::AISLE_HALF) {
                    continue; // radial aisle
                }
                $sector = ((int) floor($angle / $slice)) % self::SECTORS;
                $sectionIndex = 1 + ($isUpper ? self::SECTORS : 0) + $sector;
                $seatNo = ($counters[$sectionIndex][$ring] ?? 0) + 1;
                $counters[$sectionIndex][$ring] = $seatNo;
                $type = match (true) {
                    !$isUpper && $ringInTier < 2 => SeatType::Premium,
                    $lastRingOfTier && 0 === $seatNo % 25 => SeatType::Ada,
                    default => SeatType::Standard,
                };
                $this->seats[] = new SeatPoint($sectionIndex, $ring, self::rowLabel($ringInTier + 1), $seatNo, $type, $cx + $x, $cy + $y);
            }
            ++$ring;
        }
        $this->upperRings = $ring - self::LOWER_RINGS;

        // Ring ranges and label positions per sector.
        $lowerLabelOff = self::FIRST_GAP + (self::LOWER_RINGS - 0.5) * self::PITCH + self::CONCOURSE / 2;
        $upperLabelOff = self::FIRST_GAP + ($ring - 1) * self::PITCH + self::CONCOURSE + 14;
        foreach ($this->sections as $i => $s) {
            if (SectionTier::Floor === $s->tier) {
                continue;
            }
            $isUpper = SectionTier::Upper === $s->tier;
            [$lx, $ly] = $this->pointAtAngle(($s->angleStart + $s->angleEnd) / 2, $isUpper ? $upperLabelOff : $lowerLabelOff);
            $this->sections[$i] = new SectionShape(
                $s->code,
                $s->tier,
                $s->angleStart,
                $s->angleEnd,
                $isUpper ? self::LOWER_RINGS : 0,
                $isUpper ? $ring - 1 : self::LOWER_RINGS - 1,
                $cx + $lx,
                $cy + $ly,
            );
        }
    }

    /** @return array{float, float, float} half-width, half-height, corner radius */
    private function ringGeometry(float $off): array
    {
        return [self::FLOOR_W / 2 + $off, self::FLOOR_H / 2 + $off, self::CORNER + $off];
    }

    private function perimeter(float $a, float $b, float $cr): float
    {
        return 4 * ($b - $cr) + 4 * ($a - $cr) + 2 * \M_PI * $cr;
    }

    /**
     * Point at arc length $s along a rounded rectangle centred on the origin, starting at (a, 0) and going clockwise in screen space.
     *
     * @return array{float, float}
     */
    private function pointAt(float $s, float $a, float $b, float $cr): array
    {
        $l1 = $b - $cr;
        $la = \M_PI * $cr / 2;
        $l3 = 2 * ($a - $cr);
        $l5 = 2 * ($b - $cr);
        /** @var list<array{0: 'l'|'a', 1: array{float, float}, 2: array{float, float}|float, 3: float}> $segments */
        $segments = [
            ['l', [$a, 0.0], [$a, $l1], $l1],
            ['a', [$a - $cr, $b - $cr], 0.0, $la],
            ['l', [$a - $cr, $b], [-($a - $cr), $b], $l3],
            ['a', [-($a - $cr), $b - $cr], \M_PI / 2, $la],
            ['l', [-$a, $b - $cr], [-$a, -($b - $cr)], $l5],
            ['a', [-($a - $cr), -($b - $cr)], \M_PI, $la],
            ['l', [-($a - $cr), -$b], [$a - $cr, -$b], $l3],
            ['a', [$a - $cr, -($b - $cr)], 1.5 * \M_PI, $la],
            ['l', [$a, -($b - $cr)], [$a, 0.0], $l1],
        ];
        foreach ($segments as [$kind, $p, $q, $len]) {
            if ($s <= $len) {
                $t = $len > 0 ? $s / $len : 0.0;
                if ('l' === $kind) {
                    /** @var array{float, float} $q */
                    return [$p[0] + ($q[0] - $p[0]) * $t, $p[1] + ($q[1] - $p[1]) * $t];
                }
                /** @var float $q */
                $ang = $q + $t * \M_PI / 2;

                return [$p[0] + $cr * cos($ang), $p[1] + $cr * sin($ang)];
            }
            $s -= $len;
        }

        return [$a, 0.0];
    }

    /** @return array{float, float} the ring point whose polar angle is closest to $angle */
    private function pointAtAngle(float $angle, float $off): array
    {
        [$a, $b, $cr] = $this->ringGeometry($off);
        $perimeter = $this->perimeter($a, $b, $cr);
        $best = [$a, 0.0];
        $bestDelta = \INF;
        for ($i = 0; $i < 720; ++$i) {
            [$x, $y] = $this->pointAt($i / 720 * $perimeter, $a, $b, $cr);
            $delta = abs(fmod(atan2($y, $x) + 2 * \M_PI, 2 * \M_PI) - $angle);
            if ($delta < $bestDelta) {
                $bestDelta = $delta;
                $best = [$x, $y];
            }
        }

        return $best;
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
