<?php

declare(strict_types=1);

namespace App\Tests\Http;

use App\Domain\Ticket\TicketStatus;
use App\Http\ArenaController;
use App\Http\DemoController;
use App\Http\HealthController;
use App\Http\JobController;
use App\Http\MetricsController;
use App\Http\PlatformController;
use App\Http\RunController;
use App\Http\WebhookController;
use App\Http\WorkersController;
use PHPUnit\Framework\Attributes\CoversClass;

/** One request per controller against the seeded arena, plus the edges the review called out. Nothing here starts a run or scales. */
#[CoversClass(ArenaController::class)]
#[CoversClass(DemoController::class)]
#[CoversClass(HealthController::class)]
#[CoversClass(JobController::class)]
#[CoversClass(MetricsController::class)]
#[CoversClass(PlatformController::class)]
#[CoversClass(RunController::class)]
#[CoversClass(WebhookController::class)]
#[CoversClass(WorkersController::class)]
final class ControllersTest extends ApiCase
{
    public function testHealth(): void
    {
        self::assertSame(200, $this->json('GET', '/api/health'));
        self::assertSame('ok', $this->body()['status']);
    }

    public function testBootstrapDescribesTheArena(): void
    {
        self::assertSame(200, $this->json('GET', '/api/bootstrap'));
        self::assertCount(32, $this->bodyArray('sections'));
    }

    public function testMetricsAndPlatformsListTheThreeMarketplaces(): void
    {
        self::assertSame(200, $this->json('GET', '/api/metrics'));
        self::assertCount(3, $this->bodyArray('platforms'));
        self::assertSame(200, $this->json('GET', '/api/platforms'));
        self::assertCount(3, $this->body());
    }

    public function testUnknownPlatformIs404NotAValueError(): void
    {
        self::assertSame(404, $this->json('PUT', '/api/platforms/nope/chaos', ['failureRate' => 0.1]));
        self::assertSame('unknown platform', $this->body()['error']);
    }

    public function testFormEncodedPayloadsAreRefused(): void
    {
        $this->client->request('POST', '/api/workers/scale', ['workers' => 24], server: ['HTTP_ORIGIN' => 'http://localhost:5173']);
        self::assertSame(415, $this->client->getResponse()->getStatusCode());
    }

    public function testMutatingRequestsFromAForeignOriginAreRefused(): void
    {
        self::assertSame(403, $this->json('POST', '/api/demo/reset', origin: 'http://evil.example'));
        self::assertSame(200, $this->json('GET', '/api/workers', origin: 'http://evil.example'), 'reads are only CORS-hidden, never refused');
        self::assertSame('http://localhost:5173', $this->client->getResponse()->headers->get('Access-Control-Allow-Origin'));
    }

    public function testWorkersExposeTheAutoscaleBounds(): void
    {
        self::assertSame(200, $this->json('GET', '/api/workers'));
        self::assertSame(['min' => 2, 'max' => 32], array_intersect_key($this->bodyArray('autoscale'), ['min' => 1, 'max' => 1]));
    }

    public function testRunValidationAndJobLookups(): void
    {
        self::assertSame(422, $this->json('POST', '/api/runs', ['type' => 'nope']));
        self::assertSame(200, $this->json('GET', '/api/runs/current'));
        self::assertSame(404, $this->json('GET', '/api/jobs/0'));
        self::assertSame(404, $this->json('GET', '/api/runs/00000000-0000-0000-0000-000000000000/jobs?status=bogus'));
    }

    public function testWebhookRejectsBadSignaturesAndTicketsThePlatformDoesNotList(): void
    {
        self::assertSame(401, $this->json('POST', '/api/webhooks/tixhub', ['event' => 'listing.sold']));

        $secret = $this->db->fetchOne("SELECT webhook_secret FROM platforms WHERE code = 'tixhub'");
        // Any unsold ticket that TixHub does not list: house inventory or another marketplace's listing.
        /** @var array{id: int|string, status: string}|false $ticket */
        $ticket = $this->db->fetchAssociative("SELECT t.id, t.status FROM tickets t LEFT JOIN platforms p ON p.id = t.platform_id WHERE t.status <> ? AND (p.code IS NULL OR p.code <> 'tixhub') ORDER BY t.id LIMIT 1", [TicketStatus::Sold->value]);
        if (!\is_string($secret) || false === $ticket) {
            self::markTestSkipped('arena not seeded or every ticket is listed on TixHub');
        }
        $body = json_encode(['event' => 'listing.sold', 'delivery_id' => bin2hex(random_bytes(8)), 'ticket_id' => (int) $ticket['id'], 'sold_price_cents' => 100], \JSON_THROW_ON_ERROR);
        $this->client->request('POST', '/api/webhooks/tixhub', server: ['CONTENT_TYPE' => 'application/json', 'HTTP_X_SIGNATURE' => 'sha256=' . hash_hmac('sha256', $body, $secret)], content: $body);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('invalid', $this->body()['outcome'], 'a platform may only sell what it lists');
        self::assertSame($ticket['status'], $this->db->fetchOne('SELECT status FROM tickets WHERE id = ?', [(int) $ticket['id']]));
    }
}
