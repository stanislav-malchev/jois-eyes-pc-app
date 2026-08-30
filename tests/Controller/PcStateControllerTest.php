<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Structural smoke test only, deliberately not asserting `reachable`/
 * `verdict` values: this route always attempts a real tailnet resolve +
 * HTTP call to the WindowsEyes agent (see App\Service\PcState\
 * PcStateResolver), which isn't set up to succeed from a test run — it'll
 * fall straight through to the honest-unreachable shape.
 * App\Tests\Service\PcState\PcStateResolverTest exercises the actual
 * decoration logic against controlled inputs.
 */
class PcStateControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testReturnsTheSameDocumentTheMcpToolReturns(): void
    {
        $this->client->request('GET', '/api/v1/pc-state');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('application/json', $this->client->getResponse()->headers->get('Content-Type'));

        $body = json_decode($this->client->getResponse()->getContent(), true);
        foreach (['server_time', 'reachable', 'idle_seconds', 'idle_relative', 'verdict'] as $key) {
            self::assertArrayHasKey($key, $body);
        }
        self::assertSame('Europe/Sofia', $body['server_time']['timezone']);
    }
}
