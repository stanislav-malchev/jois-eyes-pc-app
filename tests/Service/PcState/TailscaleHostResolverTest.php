<?php

namespace App\Tests\Service\PcState;

use App\Service\PcState\TailscaleHostResolver;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the JSON-parsing/matching logic against canned
 * `tailscale status --json` output (real shape captured 30.08.2026 on the
 * dev box) via a subclass stubbing the process boundary — no real
 * `tailscale` binary or tailnet needed.
 *
 * The Self node's `HostName` here is deliberately identical to a peer's
 * (both "DESKTOP-MSI") — the real shape found live: a WSL2 guest inherits
 * its Windows host's OS-level machine name, so this box's own Self node
 * and the actual Windows-native "desktop-msi" peer report the same
 * `HostName`. Matching on `HostName` used to silently resolve to the
 * wrong node's IP (this box's own) — see resolveIp()'s docblock comment.
 * Only `DNSName` (the tailnet device name) is actually unique.
 */
class TailscaleHostResolverTest extends TestCase
{
    private const SAMPLE_JSON = <<<'JSON'
        {
            "Self": {
                "HostName": "DESKTOP-MSI",
                "DNSName": "joi.tail19416c.ts.net.",
                "TailscaleIPs": ["100.80.160.112", "fd7a:115c:a1e0::2d01:a0b0"]
            },
            "Peer": {
                "node1": {
                    "HostName": "DESKTOP-MSI",
                    "DNSName": "desktop-msi.tail19416c.ts.net.",
                    "TailscaleIPs": ["100.80.216.114", "fd7a:115c:a1e0::8601:d8e5"]
                },
                "node2": {
                    "HostName": "STANISLAV's S22",
                    "DNSName": "stanislavs-s22.tail19416c.ts.net.",
                    "TailscaleIPs": ["100.72.92.4"]
                }
            }
        }
        JSON;

    public function testResolvesIpForMatchingHostnameCaseInsensitively(): void
    {
        $resolver = $this->resolverReturning(self::SAMPLE_JSON);

        self::assertSame('100.80.216.114', $resolver->resolveIp('desktop-msi'));
    }

    public function testPicksIpv4OverIpv6WhenBothArePresent(): void
    {
        $resolver = $this->resolverReturning(self::SAMPLE_JSON);

        self::assertSame('100.80.216.114', $resolver->resolveIp('DESKTOP-MSI'));
    }

    public function testReturnsNullWhenHostnameNotFound(): void
    {
        $resolver = $this->resolverReturning(self::SAMPLE_JSON);

        self::assertNull($resolver->resolveIp('some-other-box'));
    }

    public function testMatchesSelfNodeByItsOwnDnsNameDespiteSharingAPeersHostName(): void
    {
        $resolver = $this->resolverReturning(self::SAMPLE_JSON);

        self::assertSame('100.80.160.112', $resolver->resolveIp('joi'));
    }

    /**
     * The regression case: both Self and node1 report `HostName:
     * "DESKTOP-MSI"`. Asking for "desktop-msi" must resolve to the real
     * peer (node1, 100.80.216.114) even though Self is checked first —
     * never to Self's own IP just because its HostName also matches.
     */
    public function testDoesNotMatchSelfOnASharedHostNameWhenDnsNameDiffers(): void
    {
        $resolver = $this->resolverReturning(self::SAMPLE_JSON);

        self::assertNotSame('100.80.160.112', $resolver->resolveIp('desktop-msi'));
        self::assertSame('100.80.216.114', $resolver->resolveIp('desktop-msi'));
    }

    public function testReturnsNullOnMalformedJson(): void
    {
        $resolver = $this->resolverReturning('not json');

        self::assertNull($resolver->resolveIp('desktop-msi'));
    }

    public function testReturnsNullWhenProcessBoundaryFails(): void
    {
        $resolver = $this->resolverReturning(null);

        self::assertNull($resolver->resolveIp('desktop-msi'));
    }

    private function resolverReturning(?string $json): TailscaleHostResolver
    {
        return new class($json) extends TailscaleHostResolver {
            public function __construct(private readonly ?string $json)
            {
            }

            protected function fetchStatusJson(): ?string
            {
                return $this->json;
            }
        };
    }
}
