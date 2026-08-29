<?php

namespace App\Tests\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Structural smoke test only — same reasoning as
 * App\Tests\Controller\CurrentStateControllerTest: this page always
 * attempts a live phone read first (via the same App\Service\
 * CurrentState\CurrentStateResolver), which isn't reachable from a test
 * run, so it'll fall through to whatever this machine's real cache/DB
 * state actually is.
 */
class CurrentStateControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testPageRendersTheSameDocumentAsTheApiRoute(): void
    {
        $this->client->request('GET', '/admin/current-state');

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $content = $this->client->getResponse()->getContent();
        self::assertStringContainsString('Current State (The Watcher)', $content);
        self::assertStringContainsString('server_time', $content);
        self::assertStringContainsString('Europe/Sofia', $content);
    }
}
