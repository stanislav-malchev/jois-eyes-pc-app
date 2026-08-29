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
 * Returns the most recent sleep session record.
 */
class GetLastNightSleepTool implements StreamableToolInterface
{
    public function __construct(private readonly RecordRepository $records)
    {
    }

    public function getName(): string
    {
        return 'get_last_night_sleep';
    }

    public function getDescription(): string
    {
        return 'Returns details of the most recent sleep session (duration, stages).';
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
            title: 'Get last night sleep',
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );
    }

    public function execute(array $arguments): ToolResultInterface
    {
        $record = $this->records->createQueryBuilder('r')
            ->andWhere('r.type IN (:types)')
            ->andWhere('r.deleted = false')
            ->setParameter('types', RecordType::variants(RecordType::SLEEP->value))
            ->orderBy('r.startTime', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$record) {
            return new StructuredToolResult([
                'status' => 'no_data',
                'message' => 'No sleep records found.',
            ]);
        }

        $payload = $record->getPayload();
        $startTime = new \DateTimeImmutable($payload['startTime']);
        $endTime = new \DateTimeImmutable($payload['endTime']);
        $durationMinutes = round(($endTime->getTimestamp() - $startTime->getTimestamp()) / 60);

        $stages = [];
        foreach ($payload['stages'] ?? [] as $stage) {
            $stageStartTime = new \DateTimeImmutable($stage['startTime']);
            $stageEndTime = new \DateTimeImmutable($stage['endTime']);
            $stageDuration = round(($stageEndTime->getTimestamp() - $stageStartTime->getTimestamp()) / 60);

            $stageType = match($stage['stage']) {
                'awake' => 'awake',
                'sleeping' => 'sleeping',
                'out_of_bed' => 'out_of_bed',
                'light' => 'light',
                'deep' => 'deep',
                'rem' => 'rem',
                default => 'unknown'
            };

            if (!isset($stages[$stageType])) {
                $stages[$stageType] = 0;
            }
            $stages[$stageType] += $stageDuration;
        }

        return new StructuredToolResult([
            'status' => 'success',
            'duration_minutes' => $durationMinutes,
            'duration_formatted' => sprintf('%dh %dm', intdiv($durationMinutes, 60), $durationMinutes % 60),
            'start_time' => $startTime->format(\DateTimeInterface::ATOM),
            'end_time' => $endTime->format(\DateTimeInterface::ATOM),
            'stages_summary' => $stages,
            'relative_time' => $this->relativeTime($endTime),
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
