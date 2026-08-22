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
 * Returns the most recent location fix from the database.
 */
class GetLastLocationTool implements StreamableToolInterface
{
    public function __construct(private readonly RecordRepository $records)
    {
    }

    public function getName(): string
    {
        return 'get_last_location';
    }

    public function getDescription(): string
    {
        return 'Returns the most recent location fix (latitude, longitude, accuracy, altitude).';
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
            title: 'Get last location',
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
            ->setParameter('type', RecordType::LOCATION_FIX->value)
            ->orderBy('r.startTime', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$record) {
            return new StructuredToolResult([
                'status' => 'no_data',
                'message' => 'No location records found.',
            ]);
        }

        $payload = $record->getPayload();

        return new StructuredToolResult([
            'status' => 'success',
            'latitude' => $payload['latitude'] ?? null,
            'longitude' => $payload['longitude'] ?? null,
            'accuracy' => $payload['accuracy'] ?? null,
            'altitude' => $payload['altitude'] ?? null,
            'timestamp' => $record->getStartTime()->format(\DateTimeInterface::ATOM),
            'relative_time' => $this->relativeTime($record->getStartTime()),
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
