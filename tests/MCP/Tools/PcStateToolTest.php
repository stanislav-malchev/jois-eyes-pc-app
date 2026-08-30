<?php

namespace App\Tests\MCP\Tools;

use App\MCP\Tools\PcStateTool;
use App\Service\PcState\PcStateResolver;
use KLP\KlpMcpServer\Services\ToolService\Result\StructuredToolResult;
use PHPUnit\Framework\TestCase;

/**
 * PcStateTool is a thin MCP-protocol adapter — all the actual logic lives
 * in (and is exercised by) App\Tests\Service\PcState\PcStateResolverTest.
 * This just checks the adapter itself: metadata, and that the resolver's
 * array comes back untouched inside a StructuredToolResult.
 */
class PcStateToolTest extends TestCase
{
    public function testMetadata(): void
    {
        $tool = new PcStateTool($this->createStub(PcStateResolver::class));

        self::assertSame('pc-state', $tool->getName());
        self::assertFalse($tool->isStreaming());
        self::assertTrue($tool->getAnnotations()->isReadOnlyHint());
        self::assertFalse($tool->getAnnotations()->isDestructiveHint());
        self::assertTrue($tool->getAnnotations()->isIdempotentHint());
        self::assertSame([], $tool->getInputSchema()->getProperties());
    }

    public function testExecuteWrapsResolverArrayUnchangedInStructuredToolResult(): void
    {
        $resolved = ['server_time' => ['iso' => '2026-08-30T00:00:00+03:00'], 'reachable' => true, 'idle_seconds' => 5];

        $resolver = $this->createStub(PcStateResolver::class);
        $resolver->method('resolve')->willReturn($resolved);

        $result = (new PcStateTool($resolver))->execute([]);

        self::assertInstanceOf(StructuredToolResult::class, $result);
        self::assertSame($resolved, $result->getStructuredValue());
    }
}
