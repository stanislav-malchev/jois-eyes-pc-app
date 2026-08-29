<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Structural smoke test only, deliberately not asserting specific values:
 * this route always attempts a live phone read first (see
 * App\Service\CurrentState\CurrentStateResolver), which isn't reachable
 * from a test run — it'll fall through to the real var/breath_state.json
 * cache or the DB, whichever this machine actually has, not something
 * this test controls. App\Tests\Service\CurrentState\
 * CurrentStateResolverTest exercises the actual logic against mocked/
 * controlled inputs.
 */
class CurrentStateControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testReturnsTheSameDocumentTheMcpToolReturns(): void
    {
        $this->client->request('GET', '/api/v1/current-state');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('application/json', $this->client->getResponse()->headers->get('Content-Type'));

        $body = json_decode($this->client->getResponse()->getContent(), true);
        foreach (['server_time', 'verdict', 'looking_at_phone', 'last_activity', 'fallback'] as $key) {
            self::assertArrayHasKey($key, $body);
        }
        self::assertSame('Europe/Sofia', $body['server_time']['timezone']);
    }
}
