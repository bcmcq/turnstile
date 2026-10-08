<?php

declare(strict_types=1);

namespace App\Tests\Http;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** One client per test, the kernel kept between requests so every write lands in one transaction that is rolled back. */
abstract class ApiCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected Connection $db;

    #[\Override]
    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->db = static::getContainer()->get(Connection::class);
        $this->db->beginTransaction();
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->db->rollBack();
        parent::tearDown();
    }

    /** @param array<string, mixed> $body */
    protected function json(string $method, string $uri, array $body = [], string $origin = 'http://localhost:5173'): int
    {
        $this->client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ORIGIN' => $origin], content: json_encode($body, \JSON_THROW_ON_ERROR));

        return $this->client->getResponse()->getStatusCode();
    }

    /** @return array<string, mixed> */
    protected function body(): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true, 512, \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /** @return array<mixed> */
    protected function bodyArray(string $key): array
    {
        $v = $this->body()[$key] ?? null;
        self::assertIsArray($v, "response has no array \"{$key}\"");

        return $v;
    }
}
