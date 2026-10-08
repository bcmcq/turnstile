<?php

declare(strict_types=1);

namespace App\Application\Realtime;

/** Fixed-length ring of one-second samples per metric, oldest first when exported. */
final class SeriesBuffer
{
    /** @var array<string, list<int|float>> */
    private array $series = [];

    public function __construct(private readonly int $length = 60)
    {
    }

    public function push(string $name, int|float $value): void
    {
        $this->series[$name][] = $value;
        if (\count($this->series[$name]) > $this->length) {
            array_shift($this->series[$name]);
        }
    }

    /** @return array<string, list<int|float>> */
    public function all(): array
    {
        return $this->series;
    }
}
