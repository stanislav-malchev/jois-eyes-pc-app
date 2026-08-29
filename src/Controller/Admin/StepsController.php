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

        [$startDate, $endDate, $dateDisplayString] = SofiaDayRange::forView($baseDate, $view);

        $prevDate = $this->calculateAdjacentDate($baseDate, $view, -1);
        $nextDate = $this->calculateAdjacentDate($baseDate, $view, 1);

        $records = $this->recordRepository->createQueryBuilder('r')
            ->andWhere('r.type IN (:types)')
            ->andWhere('r.startTime >= :startDate')
            ->andWhere('r.startTime <= :endDate')
            ->andWhere('r.deleted = false')
            ->setParameter('types', RecordType::variants(RecordType::STEPS->value))
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('r.startTime', 'ASC')
            ->getQuery()
            ->getResult();

        // One point per calendar day, zero-filled for days with no data —
        // a per-record chart stopped making sense once consolidation
        // collapses (most) days to a single row; a reader wants "how many
        // steps that day", not when during the day they happened.
        $byDay = [];
        foreach ($records as $record) {
            $day = $record->getStartTime()->setTimezone($sofiaTz)->format('Y-m-d');
            $byDay[$day] = ($byDay[$day] ?? 0) + (int) ($record->getPayload()['count'] ?? 0);
        }

        $stepData = [];
        $cursor = $startDate->setTimezone($sofiaTz);
        $rangeEnd = $endDate->setTimezone($sofiaTz);
        while ($cursor <= $rangeEnd) {
            $key = $cursor->format('Y-m-d');
            $stepData[] = [
                'date' => $key,
                'label' => $cursor->format('D, M j'),
                'steps' => $byDay[$key] ?? 0,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        $dailyTotals = array_column($stepData, 'steps');
        $totalSteps = array_sum($dailyTotals);
        $daysWithData = count(array_filter($dailyTotals));

        $metrics = [
            'total' => $totalSteps,
            'daily_avg' => $daysWithData > 0 ? round($totalSteps / $daysWithData, 1) : 0,
            'best_day' => $dailyTotals === [] ? 0 : max($dailyTotals),
        ];

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
