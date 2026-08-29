<?php

namespace App\Controller\Admin;

use App\Enum\RecordType;
use App\Repository\RecordRepository;
use App\Service\Admin\SofiaDayRange;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Factory\AdminContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;

class StepsController extends AbstractController
{
    public function __construct(
        private readonly RecordRepository $recordRepository,
        private readonly AdminContextFactory $adminContextFactory,
    ) {
    }

    #[Route('/admin/steps', name: 'admin_steps')]
    public function steps(Request $request, ?AdminContext $adminContext): Response
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
            ->setParameter('types', RecordType::variants(RecordType::STEPS->value))
            ->orderBy('r.startTime', 'ASC');

        if ($view === 'A') {
            // All-time isn't tied to a navigable date — every live Steps
            // record ever, no bounds.
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

        // Daily totals, zero-filled for days with no data — a per-record
        // chart stopped making sense once consolidation collapses (most)
        // days to a single row; a reader wants "how many steps that day",
        // not when during the day they happened. Metrics (avg/best) always
        // work at day granularity, regardless of the chart's own bucketing.
        $byDay = [];
        foreach ($records as $record) {
            $day = $record->getStartTime()->setTimezone($sofiaTz)->format('Y-m-d');
            $byDay[$day] = ($byDay[$day] ?? 0) + (int) ($record->getPayload()['count'] ?? 0);
        }

        if ($view === 'A') {
            $rangeStart = $byDay === [] ? $baseDate : new \DateTimeImmutable(min(array_keys($byDay)), $sofiaTz);
            $rangeEnd = new \DateTimeImmutable('now', $sofiaTz);
        } else {
            $rangeStart = $startDate->setTimezone($sofiaTz);
            $rangeEnd = $endDate->setTimezone($sofiaTz);
        }

        $dailyTotals = [];
        for ($cursor = $rangeStart; $cursor <= $rangeEnd; $cursor = $cursor->modify('+1 day')) {
            $dailyTotals[] = $byDay[$cursor->format('Y-m-d')] ?? 0;
        }

        $totalSteps = array_sum($dailyTotals);
        $daysWithData = count(array_filter($dailyTotals));

        $metrics = [
            'total' => $totalSteps,
            'daily_avg' => $daysWithData > 0 ? round($totalSteps / $daysWithData, 1) : 0,
            'best_day' => $dailyTotals === [] ? 0 : max($dailyTotals),
        ];

        // Chart granularity: Year view buckets by month (12 bars), All Time
        // buckets by calendar year — neither a 365-bar nor a multi-thousand
        // -bar chart is a useful overview. D/W/M keep one bar per day.
        if ($view === 'A') {
            $byYear = [];
            foreach ($byDay as $day => $steps) {
                $year = substr($day, 0, 4);
                $byYear[$year] = ($byYear[$year] ?? 0) + $steps;
            }

            $stepData = [];
            for ($cursor = $rangeStart; $cursor <= $rangeEnd; $cursor = $cursor->modify('+1 year')) {
                $key = $cursor->format('Y');
                $stepData[] = [
                    'date' => $key,
                    'label' => $key,
                    'steps' => $byYear[$key] ?? 0,
                ];
            }
        } elseif ($view === 'Y') {
            $byMonth = [];
            foreach ($byDay as $day => $steps) {
                $month = substr($day, 0, 7);
                $byMonth[$month] = ($byMonth[$month] ?? 0) + $steps;
            }

            $stepData = [];
            for ($cursor = $rangeStart; $cursor <= $rangeEnd; $cursor = $cursor->modify('+1 month')) {
                $key = $cursor->format('Y-m');
                $stepData[] = [
                    'date' => $key,
                    'label' => $cursor->format('M'),
                    'steps' => $byMonth[$key] ?? 0,
                ];
            }
        } else {
            $stepData = [];
            for ($cursor = $rangeStart; $cursor <= $rangeEnd; $cursor = $cursor->modify('+1 day')) {
                $key = $cursor->format('Y-m-d');
                $stepData[] = [
                    'date' => $key,
                    'label' => $cursor->format('D, M j'),
                    'steps' => $byDay[$key] ?? 0,
                ];
            }
        }

        return $this->render('admin/steps.html.twig', [
            'metrics' => $metrics,
            'chartData' => $stepData,
            'currentDate' => $baseDate->format('Y-m-d'),
            'prevDate' => $prevDate->format('Y-m-d'),
            'nextDate' => $nextDate->format('Y-m-d'),
            'currentView' => $view,
            'dateDisplayString' => $dateDisplayString,
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

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            DashboardController::class => DashboardController::class,
        ]);
    }
}
