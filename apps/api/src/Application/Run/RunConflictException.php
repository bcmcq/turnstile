<?php

declare(strict_types=1);

namespace App\Application\Run;

/** Mapped to HTTP 409 by the controller: another run is active, or the request is not valid for the current state. */
final class RunConflictException extends \RuntimeException
{
}
