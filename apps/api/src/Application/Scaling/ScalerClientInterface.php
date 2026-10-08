<?php

declare(strict_types=1);

namespace App\Application\Scaling;

/** Boundary to whatever runs the workers: the Docker-socket sidecar here, a Kubernetes HPA in production. */
interface ScalerClientInterface
{
    /**
     * Asks for the fleet to be resized; the sidecar accepts immediately and works in the background,
     * so `after` is the expected size, not a confirmation. Heartbeats tell the truth.
     *
     * @throws ScalerException
     */
    public function scale(int $workers): ScaleResult;

    /** @return list<string> running worker container names @throws ScalerException */
    public function running(): array;
}
