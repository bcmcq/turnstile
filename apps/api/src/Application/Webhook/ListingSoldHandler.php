<?php

declare(strict_types=1);

namespace App\Application\Webhook;

use App\Application\Job\TicketChange;
use App\Application\Job\TicketRepository;
use App\Application\Platform\PlatformRepository;
use App\Application\Platform\PlatformRow;
use App\Application\Realtime\EventRecorder;
use App\Domain\Job\JobStatus;
use App\Domain\Job\WebhookOutcome;
use App\Domain\Platform\PlatformCode;
use App\Domain\Ticket\TicketStatus;
use App\Infrastructure\Redis\RedisFactory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Inbound marketplace webhook: a buyer bought a listing while our bulk job may be mid-flight.
 * Same version-checked UPDATE as the workers use, so whichever writer is second has to retry.
 */
final readonly class ListingSoldHandler
{
    private const int MAX_RETRIES = 3;

    public function __construct(
        private Connection $db,
        private TicketRepository $tickets,
        private PlatformRepository $platforms,
        private EventRecorder $events,
        private RedisFactory $redis,
    ) {
    }

    public function verify(PlatformCode $code, string $rawBody, string $signatureHeader): bool
    {
        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $this->platforms->byCode($code)->webhookSecret);

        return hash_equals($expected, $signatureHeader);
    }

    /** @param array<string, mixed> $payload */
    public function handle(PlatformCode $code, array $payload): WebhookOutcome
    {
        $platform = $this->platforms->byCode($code);
        $deliveryId = \is_string($payload['delivery_id'] ?? null) ? $payload['delivery_id'] : '';
        $ticketId = is_numeric($payload['ticket_id'] ?? null) ? (int) $payload['ticket_id'] : 0;
        $soldPrice = is_numeric($payload['sold_price_cents'] ?? null) ? (int) $payload['sold_price_cents'] : null;
        $externalRef = \is_string($payload['external_ref'] ?? null) && '' !== $payload['external_ref'] ? $payload['external_ref'] : null;
        $event = \is_string($payload['event'] ?? null) ? $payload['event'] : 'unknown';

        if ('listing.sold' !== $event || '' === $deliveryId || 0 === $ticketId) {
            $this->record($platform, $deliveryId, $event, null, $payload, WebhookOutcome::Invalid);

            return WebhookOutcome::Invalid;
        }

        $outcome = WebhookOutcome::Applied;
        for ($i = 0; $i < self::MAX_RETRIES; ++$i) {
            $ticket = $this->tickets->find($ticketId);
            if (null === $ticket) {
                $outcome = WebhookOutcome::Invalid;
                break;
            }
            if (TicketStatus::Sold === $ticket->status) {
                $outcome = WebhookOutcome::IgnoredAlreadySold;
                break;
            }
            // Only a listed ticket can be bought: on this platform, or mid-transfer from another one (the mock fires
            // this webhook before answering the create call, so our row has not caught up yet). House inventory is
            // never for sale, so a first listing cannot be sniped.
            $listedHere = $ticket->platformId === $platform->id;
            if (TicketStatus::Listed !== $ticket->status || (!$listedHere && !$this->isListingInFlight($ticketId, $platform->id))) {
                $outcome = WebhookOutcome::Invalid;
                break;
            }
            // The row points at the selling platform afterwards, even when the sale landed mid-transfer.
            $change = \is_string($externalRef) ? TicketChange::sold($platform->id, $externalRef) : TicketChange::status(TicketStatus::Sold);
            if ($this->tickets->apply($ticket, $change, null)) {
                $this->events->push('ticket.updated', ['ticketId' => $ticket->id, 'sectionId' => $ticket->sectionId, 'state' => TicketStatus::Sold->code(), 'platformId' => $change->listing->platformId ?? $ticket->platformId, 'priceCents' => $soldPrice ?? $ticket->priceCents, 'runId' => null]);
                $this->events->push('webhook.received', ['platform' => $code->value, 'ticketId' => $ticket->id, 'event' => $event, 'soldPriceCents' => $soldPrice, 'conflictRetried' => $i > 0]);
                break;
            }
            // The worker won the race this time: re-read and try again (and count it, it is the point of the demo).
            $outcome = WebhookOutcome::ConflictRetried;
            $this->redis->get()->incr('webhook_conflicts');
        }

        try {
            $this->record($platform, $deliveryId, $event, $ticketId, $payload, $outcome);
        } catch (UniqueConstraintViolationException) {
            // Same delivery replayed; the first one already did the work.
        }

        return $outcome;
    }

    private function isListingInFlight(int $ticketId, int $platformId): bool
    {
        return false !== $this->db->fetchOne('SELECT 1 FROM jobs WHERE ticket_id = ? AND platform_id = ? AND status = ? LIMIT 1', [$ticketId, $platformId, JobStatus::InFlight->value]);
    }

    public function isDuplicate(PlatformCode $code, string $deliveryId): bool
    {
        return false !== $this->db->fetchOne('SELECT id FROM webhook_deliveries WHERE platform_id = ? AND delivery_id = ?', [$this->platforms->byCode($code)->id, $deliveryId]);
    }

    /** @param array<string, mixed> $payload */
    private function record(PlatformRow $platform, string $deliveryId, string $event, ?int $ticketId, array $payload, WebhookOutcome $outcome): void
    {
        $this->db->executeStatement(
            'INSERT INTO webhook_deliveries (platform_id, delivery_id, event, ticket_id, payload, outcome, received_at) VALUES (?, ?, ?, ?, ?, ?, NOW())',
            [$platform->id, '' === $deliveryId ? bin2hex(random_bytes(8)) : $deliveryId, $event, $ticketId, json_encode($payload, \JSON_THROW_ON_ERROR), $outcome->value],
        );
    }
}
