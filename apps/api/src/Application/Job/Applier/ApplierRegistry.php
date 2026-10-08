<?php

declare(strict_types=1);

namespace App\Application\Job\Applier;

use App\Domain\Run\RunType;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class ApplierRegistry
{
    /** @var array<string, ActionApplierInterface> */
    private array $byType = [];

    /** @param iterable<ActionApplierInterface> $appliers */
    public function __construct(#[AutowireIterator('app.action_applier')] iterable $appliers)
    {
        foreach ($appliers as $applier) {
            $this->byType[$applier->type()->value] = $applier;
        }
    }

    public function for(RunType $type): ActionApplierInterface
    {
        return $this->byType[$type->value] ?? throw new \LogicException(\sprintf('No applier for run type "%s"', $type->value));
    }
}
