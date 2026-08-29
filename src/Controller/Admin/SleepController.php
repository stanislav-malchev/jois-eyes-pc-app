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

class SleepController extends AbstractController
{
    public function __construct(
        private readonly RecordRepository $recordRepository,
        private readonly AdminContextFactory $adminContextFactory,
    ) {
    }

    #[Route('/admin/sleep', name: 'admin_sleep')]
    public function sleep(Request $request, ?AdminContext $adminContext): Response
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
            ->setParameter('types', RecordType::variants(RecordType::SLEEP->value))
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('r.startTime', 'ASC')
            ->getQuery()
            ->getResult();

        $sleepData = [];
        foreach ($records as $record) {
            $payload = $record->getPayload();
            $startTime = new \DateTimeImmutable($payload['startTime']);
            $endTime = new \DateTimeImmutable($payload['endTime']);
            $durationSeconds = $endTime->getTimestamp() - $startTime->getTimestamp();

            $stages = [];
            if (isset($payload['stages']) && is_array($payload['stages'])) {
                foreach ($payload['stages'] as $stage) {
                    $sStart = new \DateTimeImmutable($stage['startTime']);
                    $sEnd = new \DateTimeImmutable($stage['endTime']);
                    $sDuration = $sEnd->getTimestamp() - $sStart->getTimestamp();
                    $stages[] = [
                        'stage' => $stage['stage'],
                        'duration' => $sDuration,
                        'startTime' => $stage['startTime'],
                        'endTime' => $stage['endTime'],
                    ];
                }
            }

            $sleepData[] = [
                'startTime' => $payload['startTime'],
                'endTime' => $payload['endTime'],
                'duration' => $durationSeconds,
                'stages' => $stages,
            ];
        }

        $metrics = $this->calculateMetrics($sleepData);

        return $this->render('admin/sleep.html.twig', [
            'metrics' => $metrics,
            'sleepData' => $sleepData,
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

    private function calculateMetrics(array $sleepData): array
    {
        if (empty($sleepData)) {
            return [
                'totalDuration' => 0,
                'deepSleep' => 0,
                'lightSleep' => 0,
                'remSleep' => 0,
                'awake' => 0,
                'count' => 0,
            ];
        }

        $totalDuration = 0;
        $deepSleep = 0;
        $lightSleep = 0;
        $remSleep = 0;
        $awake = 0;

        foreach ($sleepData as $session) {
            $totalDuration += $session['duration'];
            foreach ($session['stages'] as $stage) {
                switch ($stage['stage']) {
                    case 'deep':
                        $deepSleep += $stage['duration'];
                        break;
                    case 'light':
                        $lightSleep += $stage['duration'];
                        break;
                    case 'rem':
                        $remSleep += $stage['duration'];
                        break;
                    case 'awake':
                    case 'out_of_bed':
                        $awake += $stage['duration'];
                        break;
                }
            }
        }

        return [
            'totalDuration' => $totalDuration,
            'deepSleep' => $deepSleep,
            'lightSleep' => $lightSleep,
            'remSleep' => $remSleep,
            'awake' => $awake,
            'count' => count($sleepData),
        ];
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            DashboardController::class => DashboardController::class,
        ]);
    }
}
