<?php

namespace App\Controller\Admin;

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

        $bpmValues = [];
        foreach ($records as $record) {
            $payload = $record->getPayload();
            // HeartRateRecord payload contains 'samples' which is an array of {beatsPerMinute: int, time: string}
            // or sometimes it might be just a single value depending on implementation.
            // Based on previous search, it has 'samples'.
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

        if ($view === 'D') {
            // Raw samples, chronological — intraday BPM variation is
            // meaningful, unlike Steps.
            $chartData = array_map(
                static fn (array $s) => ['label' => (new \DateTimeImmutable($s['t']))->setTimezone($sofiaTz)->format('H:i'), 'value' => $s['v']],
                $bpmValues,
            );
        } else {
            // W/M/Y aggregate to one average-BPM point per day/month across
            // the *actual* period boundaries (gaps stay null, not 0 — "0
            // BPM" isn't a real reading); A has no fixed boundary, so it
            // spans from the earliest sample to now instead.
            if ($view === 'A') {
                $rangeStart = $bpmValues === [] ? $baseDate : new \DateTimeImmutable(min(array_column($bpmValues, 't')), $sofiaTz);
                $rangeEnd = new \DateTimeImmutable('now', $sofiaTz);
            } else {
                $rangeStart = $startDate->setTimezone($sofiaTz);
                $rangeEnd = $endDate->setTimezone($sofiaTz);
            }

            $bucketFormat = $view === 'A' ? 'Y' : ($view === 'Y' ? 'Y-m' : 'Y-m-d');
            $labelFormat = $view === 'A' ? 'Y' : ($view === 'Y' ? 'M' : 'D, M j');
            $step = $view === 'A' ? '+1 year' : ($view === 'Y' ? '+1 month' : '+1 day');

            $byBucket = [];
            foreach ($bpmValues as $sample) {
                $key = (new \DateTimeImmutable($sample['t']))->setTimezone($sofiaTz)->format($bucketFormat);
                $byBucket[$key][] = $sample['v'];
            }

            $chartData = [];
            for ($cursor = $rangeStart; $cursor <= $rangeEnd; $cursor = $cursor->modify($step)) {
                $values = $byBucket[$cursor->format($bucketFormat)] ?? null;
                $chartData[] = [
                    'label' => $cursor->format($labelFormat),
                    'value' => $values === null ? null : round(array_sum($values) / count($values), 1),
                ];
            }
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
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            DashboardController::class => DashboardController::class,
        ]);
    }
}
