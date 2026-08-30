<?php

namespace App\Tests\Service\PcState;

use App\Service\PcState\PcStateClient;
use App\Service\PcState\PcStateResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PcStateResolverTest extends TestCase
{
    public function testUnreachableAgentReturnsHonestNullsNotAFabricatedAnswer(): void
    {
        $client = $this->createStub(PcStateClient::class);
        $client->method('fetchIdle')->willReturn(null);

        $data = (new PcStateResolver($client))->resolve();

        self::assertFalse($data['reachable']);
        self::assertNull($data['idle_seconds']);
        self::assertNull($data['idle_relative']);
        self::assertNull($data['verdict']);
        self::assertSame('Europe/Sofia', $data['server_time']['timezone']);
    }

    #[DataProvider('verdictThresholds')]
    public function testVerdictThresholds(int $idleSeconds, string $expectedVerdict): void
    {
        $client = $this->createStub(PcStateClient::class);
        $client->method('fetchIdle')->willReturn(['idle_seconds' => $idleSeconds]);

        $data = (new PcStateResolver($client))->resolve();

        self::assertTrue($data['reachable']);
        self::assertSame($idleSeconds, $data['idle_seconds']);
        self::assertSame($expectedVerdict, $data['verdict']);
        self::assertNotNull($data['idle_relative']);
    }

    public static function verdictThresholds(): iterable
    {
        yield 'typing right now' => [5, 'at_desk'];
        yield 'just under the at-desk ceiling' => [119, 'at_desk'];
        yield 'just past it' => [120, 'stepped_away'];
        yield 'just under stepped-away ceiling' => [599, 'stepped_away'];
        yield 'just past it too' => [600, 'away'];
        yield 'just under away ceiling' => [3599, 'away'];
        yield 'an hour idle' => [3600, 'desk_asleep'];
        yield 'well past an hour' => [7200, 'desk_asleep'];
    }

    public function testRawAgentFieldsPassThroughUntouchedAlongsideDecorations(): void
    {
        $client = $this->createStub(PcStateClient::class);
        // A stand-in for a milestone-2 field the agent doesn't send yet —
        // proves raw fields survive undecorated, same rule as
        // CurrentStateResolver.
        $client->method('fetchIdle')->willReturn(['idle_seconds' => 10, 'lock_state' => 'unlocked']);

        $data = (new PcStateResolver($client))->resolve();

        self::assertSame('unlocked', $data['lock_state']);
    }

    /**
     * The real agent shape as of 30.08.2026 (milestone 2 start) carries its
     * own `server_time`/`ts` (see the LLM wiki's concepts/pc-presence-agent.md).
     * This tool's own `server_time` must stay the wsl-server host's clock —
     * the agent's reading must not silently overwrite it, nor be dropped.
     */
    public function testAgentsOwnServerTimeIsKeptSeparatelyNotDroppedOrOverwritingOurs(): void
    {
        $client = $this->createStub(PcStateClient::class);
        $client->method('fetchIdle')->willReturn([
            'server_time' => ['iso' => '2026-08-30T12:46:35+03:00', 'timezone' => 'Europe/Sofia', 'unix' => 1788083195],
            'idle_seconds' => 3,
            'ts' => 1788083195872,
        ]);

        $data = (new PcStateResolver($client))->resolve();

        self::assertSame(1788083195872, $data['ts']);
        self::assertSame(1788083195, $data['agent_server_time']['unix']);
        // Our own server_time is a fresh reading from *this* host's clock,
        // not a copy of the agent's — assert it exists and is well-formed
        // rather than pinning it to the agent's fixture value.
        self::assertSame('Europe/Sofia', $data['server_time']['timezone']);
        self::assertArrayHasKey('unix', $data['server_time']);
    }
}
