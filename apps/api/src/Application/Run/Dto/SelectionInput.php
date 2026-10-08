<?php

declare(strict_types=1);

namespace App\Application\Run\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class SelectionInput
{
    /**
     * @param list<int> $sections
     * @param list<int> $tickets
     */
    public function __construct(
        #[Assert\All([new Assert\Type('int'), new Assert\Positive()])]
        public array $sections = [],
        #[Assert\All([new Assert\Type('int'), new Assert\Positive()])]
        public array $tickets = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->sections && [] === $this->tickets;
    }
}
