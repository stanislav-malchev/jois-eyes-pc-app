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
 * Returns the most recent heart rate reading from the database.
 */
readonly class GetLastHeartRateTool implements StreamableToolInterface
{
    public function __construct(private RecordRepository $records)
    {
    }

    public function getName(): string
    {
        return 'get_last_heartrate';
    }

    public function getDescription(): string
    {
        return 'Returns the most recent heart rate reading (BPM).';
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
            title: 'Get last heart rate',
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );
    }

    public function execute(array $arguments): ToolResultInterface
    {
        $record = $this->records->createQueryBuilder('r')
            ->andWhere('r.type = :type')
            ->andWhere('r.deleted = false')
            ->setParameter('type', RecordType::HEART_RATE->value)
            ->orderBy('r.startTime', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$record) {
            return new StructuredToolResult([
                'status' => 'no_data',
                'message' => 'No heart rate records found.',
            ]);
        }

        $payload = $record->getPayload();
        $samples = $payload['samples'] ?? [];
        $latestSample = end($samples);

        if (!$latestSample || !isset($latestSample['beatsPerMinute'])) {
            return new StructuredToolResult([
                'status' => 'no_data',
                'message' => 'Heart rate record found, but no samples present.',
            ]);
        }

        $sampleTime = isset($latestSample['time']) ? new \DateTimeImmutable($latestSample['time']) : $record->getStartTime();

        return new StructuredToolResult([
            'status' => 'success',
            'bpm' => (int) $latestSample['beatsPerMinute'],
            'timestamp' => $sampleTime->format(\DateTimeInterface::ATOM),
            'relative_time' => $this->relativeTime($sampleTime),
        ]);
    }

    public function isStreaming(): bool
    {
        return false;
    }

    public function setProgressNotifier(ProgressNotifierInterface $progressNotifier): void
    {
    }

    private function relativeTime(\DateTimeImmutable $time): string
    {
        $seconds = (new \DateTimeImmutable())->getTimestamp() - $time->getTimestamp();

        if ($seconds < 60) {
            return 'just now';
        }
        if ($seconds < 3600) {
            $minutes = intdiv($seconds, 60);
            return sprintf('%d min%s ago', $minutes, 1 === $minutes ? '' : 's');
        }
        if ($seconds < 86400) {
            $hours = intdiv($seconds, 3600);
            return sprintf('%d hour%s ago', $hours, 1 === $hours ? '' : 's');
        }
        $days = intdiv($seconds, 86400);
        return sprintf('%d day%s ago', $days, 1 === $days ? '' : 's');
    }
}
