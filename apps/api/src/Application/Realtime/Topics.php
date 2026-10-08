<?php

declare(strict_types=1);

namespace App\Application\Realtime;

/** Mercure topics the dashboard subscribes to. */
final class Topics
{
    public const string METRICS = 'turnstile/metrics'; // MetricsSnapshot, at most 4×/s, at least 1×/s
    public const string SEATS = 'turnstile/seats';     // {rows: [[ticketId, sectionId, state, platformId, priceCents], …]} only when seats changed
    public const string LOG = 'turnstile/log';         // {events: [{type, ts, …payload}, …]} raw events for the Events tab
    public const string RUN = 'turnstile/run';         // RunView on lifecycle changes, {reset: true} on demo reset

    /** @return list<string> */
    public static function all(): array
    {
        return [self::METRICS, self::SEATS, self::LOG, self::RUN];
    }
}
