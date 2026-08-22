<?php

namespace App\Controller\Admin;

use App\Enum\RecordType;
use App\Repository\RecordRepository;
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

        $dateStr = $request->query->get('date', date('Y-m-d'));
        $view = $request->query->get('view', 'D');

        try {
            $baseDate = new \DateTimeImmutable($dateStr);
        } catch (\Exception) {
            $baseDate = new \DateTimeImmutable();
        }

        [$startDate, $endDate, $dateDisplayString] = $this->calculateTimeRange($baseDate, $view);

        $prevDate = $this->calculateAdjacentDate($baseDate, $view, -1);
        $nextDate = $this->calculateAdjacentDate($baseDate, $view, 1);

        $records = $this->recordRepository->createQueryBuilder('r')
            ->andWhere('r.type = :type')
            ->andWhere('r.startTime >= :startDate')
            ->andWhere('r.startTime <= :endDate')
            ->andWhere('r.deleted = false')
            ->setParameter('type', RecordType::STEPS->value)
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('r.startTime', 'ASC')
            ->getQuery()
            ->getResult();

        $stepData = [];
        $totalSteps = 0;
        foreach ($records as $record) {
            $payload = $record->getPayload();
            $count = (int)($payload['count'] ?? 0);
            $totalSteps += $count;
            $stepData[] = [
                't' => $record->getStartTime()->format(\DateTimeInterface::ATOM),
                'v' => $count
            ];
        }

        $metrics = [
            'total' => $totalSteps,
            'hourly_avg' => count($stepData) > 0 ? round($totalSteps / max(1, count($stepData)), 1) : 0,
            'max_burst' => count($stepData) > 0 ? max(array_column($stepData, 'v')) : 0,
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

    private function calculateTimeRange(\DateTimeImmutable $date, string $view): array
    {
        switch ($view) {
            case 'W':
                $start = $date->modify('monday this week')->setTime(0, 0, 0);
                $end = $start->modify('+6 days')->setTime(23, 59, 59);
                $display = sprintf('Week of %s', $start->format('M d, Y'));
                break;
            case 'M':
                $start = $date->modify('first day of this month')->setTime(0, 0, 0);
                $end = $date->modify('last day of this month')->setTime(23, 59, 59);
                $display = $start->format('F Y');
                break;
            case 'D':
            default:
                $start = $date->setTime(0, 0, 0);
                $end = $date->setTime(23, 59, 59);
                $display = $start->format('F d, Y');
                break;
        }

        return [$start, $end, $display];
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            DashboardController::class => DashboardController::class,
        ]);
    }
}
