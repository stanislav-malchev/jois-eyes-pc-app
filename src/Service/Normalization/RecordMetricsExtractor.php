<?php

namespace App\Service\Normalization;

use App\Entity\Record;
use App\Enum\RecordType;

/**
 * Populates Record's denormalized metric columns from payload_json —
 * payload stays the source of truth, these are a derived read cache.
 * Recomputed from payload every time, never hand-edited; safe to call
 * repeatedly (idempotent) or drop and regenerate.
 *
 * Only Steps and HeartRate are handled so far (see CLAUDE.md's open-type
 * contract — 'type' is an open set, and this extractor mirrors that:
 * anything else just gets nulled out here rather than rejected). Add a
 * case to extract() when another type's fields are worth caching, and
 * check that type's real payload shape first — don't assume it looks like
 * either of these two (see the per-type-consolidation-semantics lesson:
 * Steps is a scalar daily count, HeartRate is a sample array, and a third
 * type could easily be neither).
 */
final class RecordMetricsExtractor
{
    public function extract(Record $record): void
    {
        $payload = $record->getPayload();

        $dataOrigin = $payload['dataOrigin'] ?? null;
        $record->setDataOrigin(is_string($dataOrigin) ? $dataOrigin : null);

        match (RecordType::canonicalize($record->getType())) {
            'Steps' => $this->extractSteps($record, $payload),
            'HeartRate' => $this->extractHeartRate($record, $payload),
            default => $this->clearMetrics($record),
        };
    }

    /**
     * Steps payload is one scalar daily/bucket count, not a sample array —
     * see docs/02-data-model.md. metricMin/Max would just duplicate
     * metricValue, so they're left null rather than filled in redundantly.
     */
    private function extractSteps(Record $record, array $payload): void
    {
        $count = $payload['count'] ?? null;
        if (!is_numeric($count)) {
            $this->clearMetrics($record);

            return;
        }

        $record->setMetricValue((float) $count)->setMetricMin(null)->setMetricMax(null)->setSampleCount(1);
    }

    /**
     * HeartRate payload is a samples array of {time, beatsPerMinute} — see
     * docs/02-data-model.md. metricValue is the row's average bpm;
     * metricMin/Max/sampleCount preserve enough of the distribution that
     * chart/summary code doesn't need to re-decode payload just to know
     * the row wasn't a single flat reading.
     */
    private function extractHeartRate(Record $record, array $payload): void
    {
        $samples = is_array($payload['samples'] ?? null) ? $payload['samples'] : [];

        $bpmValues = [];
        foreach ($samples as $sample) {
            $bpm = is_array($sample) ? ($sample['beatsPerMinute'] ?? null) : null;
            if (is_numeric($bpm)) {
                $bpmValues[] = (float) $bpm;
            }
        }

        if ($bpmValues === []) {
            $this->clearMetrics($record);

            return;
        }

        $record
            ->setMetricValue(round(array_sum($bpmValues) / count($bpmValues), 1))
            ->setMetricMin(min($bpmValues))
            ->setMetricMax(max($bpmValues))
            ->setSampleCount(count($bpmValues));
    }

    private function clearMetrics(Record $record): void
    {
        $record->setMetricValue(null)->setMetricMin(null)->setMetricMax(null)->setSampleCount(null);
    }
}
