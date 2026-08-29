<?php

namespace App\Controller\Admin;

use App\Entity\Record;
use App\Enum\RecordType;
use App\Repository\RecordRepository;
use App\Service\Admin\SofiaDayRange;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Factory\AdminContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;

class HeartRateController extends AbstractController
{
    public function __construct(
        private readonly RecordRepository $recordRepository,
        private readonly AdminContextFactory $adminContextFactory,
    ) {
    }

    #[Route('/admin/heart-rate', name: 'admin_heart_rate')]
    public function heartRate(Request $request, ?AdminContext $adminContext): Response
    {
        if (null === $adminContext) {
            $dashboardController = $this->container->get(DashboardController::class);
            $adminContext = $this->adminContextFactory->create($request, $dashboardController, null, null);
            $request->attributes->set(EA::CONTEXT_REQUEST_ATTRIBUTE, $adminContext);
            $request->attributes->set('ea', $adminContext);
        }

        $sofiaTz = new \DateTimeZone(SofiaDayRange::TIMEZONE);
        $dateStr = $request->query->get('date', (new \DateTimeImmutable('now', $sofiaTz))->format('Y-m-d'));
        $view = $request->query->get('view', 'D');

        try {
            $baseDate = new \DateTimeImmutable($dateStr, $sofiaTz);
        } catch (\Exception) {
            $baseDate = new \DateTimeImmutable('now', $sofiaTz);
        }

        $recordsQuery = $this->recordRepository->createQueryBuilder('r')
            ->andWhere('r.type IN (:types)')
            ->andWhere('r.deleted = false')
            ->setParameter('types', RecordType::variants(RecordType::HEART_RATE->value))
            ->orderBy('r.startTime', 'ASC');

        if ($view === 'A') {
            // All-time isn't tied to a navigable date — every live
            // HeartRate record ever, no bounds.
            $dateDisplayString = 'All Time';
            $prevDate = $nextDate = $baseDate;
            $records = $recordsQuery->getQuery()->getResult();
        } else {
            [$startDate, $endDate, $dateDisplayString] = SofiaDayRange::forView($baseDate, $view);
            $prevDate = $this->calculateAdjacentDate($baseDate, $view, -1);
            $nextDate = $this->calculateAdjacentDate($baseDate, $view, 1);
            $records = $recordsQuery
                ->andWhere('r.startTime >= :startDate')
                ->andWhere('r.startTime <= :endDate')
                ->setParameter('startDate', $startDate)
                ->setParameter('endDate', $endDate)
                ->getQuery()
                ->getResult();
        }

        if ($view === 'D') {
            // Raw samples, chronological — intraday BPM variation is
            // meaningful, unlike Steps. Only the day view needs per-minute
            // granularity, which the denormalized columns don't carry (they
            // cache one avg/min/max per row, not each individual sample) —
            // so this is the one place that still decodes payload_json.
            $bpmValues = [];
            foreach ($records as $record) {
                $payload = $record->getPayload();
                if (isset($payload['samples']) && is_array($payload['samples'])) {
                    foreach ($payload['samples'] as $sample) {
                        if (isset($sample['beatsPerMinute'])) {
                            $bpmValues[] = [
                                't' => $sample['time'] ?? $record->getStartTime()->format(\DateTimeInterface::ATOM),
                                'v' => (int) $sample['beatsPerMinute'],
                            ];
                        }
                    }
                }
            }

            $metrics = $this->calculateMetrics($bpmValues);
            $chartData = array_map(
                static fn (array $s) => ['label' => (new \DateTimeImmutable($s['t']))->setTimezone($sofiaTz)->format('H:i'), 'value' => $s['v']],
                $bpmValues,
            );
        } else {
            // W/M/Y/A read RecordMetricsExtractor's per-row metricValue/
            // metricMin/metricMax/sampleCount columns instead of decoding
            // samples — mathematically equivalent here because no HeartRate
            // row's time window crosses a day boundary, so a weighted mean
            // of per-row averages (weighted by sampleCount) equals the true
            // mean of every individual sample, and the true global min/max
            // is exactly the min/max of the per-row mins/maxes.
            $withMetrics = array_values(array_filter($records, static fn (Record $r) => $r->getMetricValue() !== null));

            if ($view === 'A') {
                $rangeStart = $withMetrics === [] ? $baseDate : $withMetrics[0]->getStartTime()->setTimezone($sofiaTz);
                $rangeEnd = new \DateTimeImmutable('now', $sofiaTz);
            } else {
                $rangeStart = $startDate->setTimezone($sofiaTz);
                $rangeEnd = $endDate->setTimezone($sofiaTz);
            }

            $bucketFormat = $view === 'A' ? 'Y' : ($view === 'Y' ? 'Y-m' : 'Y-m-d');
            $labelFormat = $view === 'A' ? 'Y' : ($view === 'Y' ? 'M' : 'D, M j');
            $step = $view === 'A' ? '+1 year' : ($view === 'Y' ? '+1 month' : '+1 day');

            $byBucket = [];
            foreach ($withMetrics as $record) {
                $key = $record->getStartTime()->setTimezone($sofiaTz)->format($bucketFormat);
                $n = $record->getSampleCount() ?? 1;
                $byBucket[$key]['sum'] = ($byBucket[$key]['sum'] ?? 0) + $record->getMetricValue() * $n;
                $byBucket[$key]['count'] = ($byBucket[$key]['count'] ?? 0) + $n;
            }

            $chartData = [];
            for ($cursor = $rangeStart; $cursor <= $rangeEnd; $cursor = $cursor->modify($step)) {
                $bucket = $byBucket[$cursor->format($bucketFormat)] ?? null;
                $chartData[] = [
                    'label' => $cursor->format($labelFormat),
                    'value' => $bucket === null ? null : round($bucket['sum'] / $bucket['count'], 1),
                ];
            }

            $metrics = $this->calculateMetricsFromRecords($withMetrics);
        }

        return $this->render('admin/heart_rate.html.twig', [
            'metrics' => $metrics,
            'chartData' => $chartData,
            'currentDate' => $baseDate->format('Y-m-d'),
            'prevDate' => $prevDate->format('Y-m-d'),
            'nextDate' => $nextDate->format('Y-m-d'),
            'currentView' => $view,
            'dateDisplayString' => $dateDisplayString,
            // EasyAdmin requirements
            'ea' => $adminContext,
            'dashboard_controller_class' => DashboardController::class,
        ]);
    }

    private function calculateAdjacentDate(\DateTimeImmutable $date, string $view, int $direction): \DateTimeImmutable
    {
        $modifier = $direction > 0 ? '+' : '-';
        switch ($view) {
            case 'Y':
                return $date->modify($modifier . '1 year');
            case 'W':
                return $date->modify($modifier . '1 week');
            case 'M':
                return $date->modify($modifier . '1 month');
            case 'D':
            default:
                return $date->modify($modifier . '1 day');
        }
    }

    private function calculateMetrics(array $bpmValues): array
    {
        if (empty($bpmValues)) {
            return [
                'min' => 0,
                'max' => 0,
                'average' => 0,
                'latest' => 0,
            ];
        }

        $values = array_column($bpmValues, 'v');
        return [
            'min' => min($values),
            'max' => max($values),
            'average' => round(array_sum($values) / count($values), 1),
            'latest' => end($bpmValues)['v'],
        ];
    }

    /**
     * Column-based equivalent of calculateMetrics() for W/M/Y/A, where
     * $records already carries metricValue/metricMin/metricMax/sampleCount
     * for every row (records are pre-filtered to only those with a
     * non-null metricValue, and pre-sorted by startTime ASC). 'latest' is
     * the most recent row's *average* bpm rather than its single most
     * recent sample — the columns don't retain individual sample order, so
     * this is a deliberate (small) precision trade for not decoding JSON.
     *
     * @param Record[] $records
     */
    private function calculateMetricsFromRecords(array $records): array
    {
        if ($records === []) {
            return [
                'min' => 0,
                'max' => 0,
                'average' => 0,
                'latest' => 0,
            ];
        }

        $weightedSum = 0.0;
        $totalSamples = 0;
        foreach ($records as $record) {
            $n = $record->getSampleCount() ?? 1;
            $weightedSum += $record->getMetricValue() * $n;
            $totalSamples += $n;
        }

        return [
            'min' => (int) min(array_map(static fn (Record $r) => $r->getMetricMin() ?? $r->getMetricValue(), $records)),
            'max' => (int) max(array_map(static fn (Record $r) => $r->getMetricMax() ?? $r->getMetricValue(), $records)),
            'average' => round($weightedSum / $totalSamples, 1),
            'latest' => (int) round(end($records)->getMetricValue()),
        ];
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            DashboardController::class => DashboardController::class,
        ]);
    }
}
