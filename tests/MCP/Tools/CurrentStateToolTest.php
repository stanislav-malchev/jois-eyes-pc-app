<?php

namespace App\Tests\MCP\Tools;

use App\MCP\Tools\CurrentStateTool;
use App\Service\CurrentState\CurrentStateResolver;
use KLP\KlpMcpServer\Services\ToolService\Result\StructuredToolResult;
use PHPUnit\Framework\TestCase;

/**
 * CurrentStateTool is a thin MCP-protocol adapter — all the actual logic
 * lives in (and is exercised by)
 * App\Tests\Service\CurrentState\CurrentStateResolverTest. This just
 * checks the adapter itself: metadata, and that the resolver's array
 * comes back untouched inside a StructuredToolResult.
 */
class CurrentStateToolTest extends TestCase
{
    public function testMetadata(): void
    {
        $tool = new CurrentStateTool($this->createStub(CurrentStateResolver::class));

        self::assertSame('current-state', $tool->getName());
        self::assertFalse($tool->isStreaming());
        self::assertTrue($tool->getAnnotations()->isReadOnlyHint());
        self::assertFalse($tool->getAnnotations()->isDestructiveHint());
        self::assertTrue($tool->getAnnotations()->isIdempotentHint());
        self::assertSame([], $tool->getInputSchema()->getProperties());
    }

    public function testExecuteWrapsResolverArrayUnchangedInStructuredToolResult(): void
    {
        $resolved = ['server_time' => ['iso' => '2026-08-29T00:00:00+03:00'], 'verdict' => 'recent'];

        $resolver = $this->createStub(CurrentStateResolver::class);
        $resolver->method('resolve')->willReturn($resolved);

        $result = (new CurrentStateTool($resolver))->execute([]);

        self::assertInstanceOf(StructuredToolResult::class, $result);
        self::assertSame($resolved, $result->getStructuredValue());
    }
}
