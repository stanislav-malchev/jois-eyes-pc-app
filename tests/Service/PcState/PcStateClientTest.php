<?php

namespace App\Tests\Service\PcState;

use App\Service\PcState\PcStateClient;
use App\Service\PcState\TailscaleHostResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class PcStateClientTest extends TestCase
{
    public function testReturnsDecodedResponseWhenAgentAnswers(): void
    {
        $httpClient = new MockHttpClient(function (string $method, string $url) {
            self::assertSame('GET', $method);
            self::assertSame('http://100.80.216.114:8789/idle', $url);

            return new MockResponse(json_encode(['idle_seconds' => 42]));
        });

        $client = new PcStateClient($this->tailscaleResolving('100.80.216.114'), $httpClient);

        self::assertSame(['idle_seconds' => 42], $client->fetchIdle());
    }

    public function testReturnsNullWhenTailscaleCannotResolveTheHost(): void
    {
        $httpClient = new MockHttpClient(function () {
            self::fail('HTTP call should never happen when the IP cannot be resolved');
        });

        $client = new PcStateClient($this->tailscaleResolving(null), $httpClient);

        self::assertNull($client->fetchIdle());
    }

    public function testReturnsNullWhenTheAgentIsUnreachable(): void
    {
        $httpClient = new MockHttpClient(function () {
            throw new TransportException('connection refused');
        });

        $client = new PcStateClient($this->tailscaleResolving('100.80.216.114'), $httpClient);

        self::assertNull($client->fetchIdle());
    }

    private function tailscaleResolving(?string $ip): TailscaleHostResolver
    {
        return new class($ip) extends TailscaleHostResolver {
            public function __construct(private readonly ?string $ip)
            {
            }

            public function resolveIp(string $hostname): ?string
            {
                \PHPUnit\Framework\Assert::assertSame('desktop-msi', $hostname);

                return $this->ip;
            }
        };
    }
}
