<?php

namespace App\MCP\Tools;

use App\Repository\RecordRepository;
use App\Enum\RecordType;
use KLP\KlpMcpServer\Services\ProgressService\ProgressNotifierInterface;
use KLP\KlpMcpServer\Services\ToolService\Annotation\ToolAnnotation;
use KLP\KlpMcpServer\Services\ToolService\Result\StructuredToolResult;
use KLP\KlpMcpServer\Services\ToolService\Result\ToolResultInterface;
use KLP\KlpMcpServer\Services\ToolService\Schema\StructuredSchema;
use KLP\KlpMcpServer\Services\ToolService\StreamableToolInterface;

/**
 * Returns the total steps for the current day.
 */
class GetLastDayStepsTool implements StreamableToolInterface
{
    private const TIMEZONE = 'Europe/Sofia';

    public function __construct(private readonly RecordRepository $records)
    {
    }

    public function getName(): string
    {
        return 'get_last_day_steps';
    }

    public function getDescription(): string
    {
        return 'Returns the total step count for the current day.';
    }

    public function getInputSchema(): StructuredSchema
    {
        return new StructuredSchema;
    }

    public function getOutputSchema(): ?StructuredSchema
    {
        return null;
    }

    public function getAnnotations(): ToolAnnotation
    {
        return new ToolAnnotation(
            title: 'Get last day steps',
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );
    }

    public function execute(array $arguments): ToolResultInterface
    {
        $timezone = new \DateTimeZone(self::TIMEZONE);
        $today = new \DateTimeImmutable('today', $timezone);
        $startOfDayUtc = $today->setTimezone(new \DateTimeZone('UTC'));

        $records = $this->records->findByTypeSince(RecordType::STEPS->value, $startOfDayUtc);

        $totalSteps = 0;
        foreach ($records as $record) {
            $payload = $record->getPayload();
            $totalSteps += (int) ($payload['count'] ?? 0);
        }

        return new StructuredToolResult([
            'status' => 'success',
            'steps' => $totalSteps,
            'date' => $today->format('Y-m-d'),
            'timezone' => self::TIMEZONE,
        ]);
    }

    public function isStreaming(): bool
    {
        return false;
    }

    public function setProgressNotifier(ProgressNotifierInterface $progressNotifier): void
    {
    }
}
