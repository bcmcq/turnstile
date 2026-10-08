<?php

declare(strict_types=1);

namespace App\Application\Run;

use Symfony\Component\Validator\Constraints as Assert;

/** Which tickets a run acts on. Comes in on POST /api/runs, lives in runs.selection as JSON, goes out on RunView. */
final readonly class Selection
{
    /**
     * @param list<int> $sections
     * @param list<int> $tickets
     */
    public function __construct(
        #[Assert\Count(max: 64)]
        #[Assert\All([new Assert\Type('int'), new Assert\Positive()])]
        public array $sections = [],
        #[Assert\Count(max: 10_000)]
        #[Assert\All([new Assert\Type('int'), new Assert\Positive()])]
        public array $tickets = [],
    ) {
    }

    public static function fromJson(string $json): self
    {
        /** @var array{sections?: list<int|string>, tickets?: list<int|string>} $data */
        $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);

        return new self(array_map(intval(...), $data['sections'] ?? []), array_map(intval(...), $data['tickets'] ?? []));
    }

    /** @return array{sections: list<int>, tickets: list<int>} for the JSON column */
    public function toArray(): array
    {
        return ['sections' => $this->sections, 'tickets' => $this->tickets];
    }

    public function isEmpty(): bool
    {
        return [] === $this->sections && [] === $this->tickets;
    }
}
