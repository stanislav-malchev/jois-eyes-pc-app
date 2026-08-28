<?php

namespace App\MCP\Tools;

use App\Backdoor\LiveVitalsResolver;
use App\Enum\RecordType;
use App\Repository\RecordRepository;
use KLP\KlpMcpServer\Services\ProgressService\ProgressNotifierInterface;
use KLP\KlpMcpServer\Services\ToolService\Annotation\ToolAnnotation;
use KLP\KlpMcpServer\Services\ToolService\Result\StructuredToolResult;
use KLP\KlpMcpServer\Services\ToolService\Result\ToolResultInterface;
use KLP\KlpMcpServer\Services\ToolService\Schema\StructuredSchema;
use KLP\KlpMcpServer\Services\ToolService\StreamableToolInterface;

/**
 * Aggregates today's Health Connect records into one summary.
 *
 * `steps` prefers a live phone snapshot (see LiveVitalsResolver), falling
 * back to summed stored records; `avg_heart_rate` is always a
 * stored-records aggregate — a live snapshot only carries one current
 * reading, not today's average. `exercise_minutes` and `perceived_stress`
 * come back null — they'll get real values once sleep and exercise-session
 * record types start syncing, without changing this tool's output shape.
 */
class DailyVitalsSummaryTool implements StreamableToolInterface
{
    private const TIMEZONE = 'Europe/Sofia';

    public function __construct(
        private readonly LiveVitalsResolver $vitals,
        private readonly RecordRepository $records,
    ) {
    }

    public function getName(): string
    {
        return 'daily-vitals-summary';
    }

    public function getDescription(): string
    {
        return 'Returns step count and average heart rate for today; workout duration and stress indicators are included as null until sleep/exercise records start syncing.';
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
            title: 'Daily vitals summary',
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );
    }

    public function execute(array $arguments): ToolResultInterface
    {
        $startOfDay = (new \DateTimeImmutable('today', new \DateTimeZone(self::TIMEZONE)))
            ->setTimezone(new \DateTimeZone('UTC'));

        $steps = $this->vitals->getStepsToday($startOfDay);

        $bpmReadings = [];
        foreach ($this->records->findByTypeSince(RecordType::HEART_RATE->value, $startOfDay) as $record) {
            foreach ($record->getPayload()['samples'] ?? [] as $sample) {
                if (isset($sample['beatsPerMinute'])) {
                    $bpmReadings[] = (int) $sample['beatsPerMinute'];
                }
            }
        }
        $avgHeartRate = $bpmReadings === [] ? null : (int) round(array_sum($bpmReadings) / count($bpmReadings));

        return new StructuredToolResult([
            'steps' => $steps['steps'],
            'steps_source' => $steps['source'],
            'avg_heart_rate' => $avgHeartRate,
            'exercise_minutes' => null,
            'perceived_stress' => null,
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
