<?php

namespace App\Tests\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Structural smoke test only — same reasoning as
 * App\Tests\Controller\PcStateControllerTest: this page always attempts a
 * real tailnet resolve + agent call, which isn't reachable from a test
 * run, so it'll fall straight through to the honest-unreachable shape.
 */
class PcStateControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testPageRendersTheSameDocumentAsTheApiRoute(): void
    {
        $this->client->request('GET', '/admin/pc-state');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('PC State (the desk tier)', $content);
        self::assertStringContainsString('server_time', $content);
        self::assertStringContainsString('Europe/Sofia', $content);
    }
}
