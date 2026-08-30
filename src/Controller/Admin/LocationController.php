<?php

namespace App\Controller\Admin;

use App\Entity\Record;
use App\Enum\RecordType;
use App\Repository\NamedLocationRepository;
use App\Repository\RecordRepository;
use App\Service\Admin\SofiaDayRange;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Factory\AdminContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Location records on a Leaflet map (pins, not a row list) — read from
 * RecordMetricsExtractor's denormalized latitude/longitude/accuracyMeters/
 * closestToId columns, same read-cache idea as the Steps/Heart Rate charts.
 */
class LocationController extends AbstractController
{
    /**
     * Fixes reported this coarse are the network/cell-tower location
     * provider, not GPS — real GPS fixes here cluster at accuracyMeters
     * ~100 or better; the fallback provider reports a flat 2000. A 2km
     * error radius easily lands a pin in the sea or a forest even when the
     * phone was stationary at home, so these are excluded from the map
     * (not from storage — RecordCrudController's "All records" still shows
     * every synced row untouched).
     */
    private const MAX_USEFUL_ACCURACY_METERS = 1000.0;

    public function __construct(
        private readonly RecordRepository $recordRepository,
        private readonly NamedLocationRepository $namedLocations,
        private readonly AdminContextFactory $adminContextFactory,
    ) {
    }

    #[Route('/admin/location', name: 'admin_location')]
    public function location(Request $request, ?AdminContext $adminContext): Response
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
        $closestToParam = $request->query->get('closestTo', 'all');
        $closestToId = ctype_digit((string) $closestToParam) ? (int) $closestToParam : null;

        try {
            $baseDate = new \DateTimeImmutable($dateStr, $sofiaTz);
        } catch (\Exception) {
            $baseDate = new \DateTimeImmutable('now', $sofiaTz);
        }

        $recordsQuery = $this->recordRepository->createQueryBuilder('r')
            ->andWhere('r.type IN (:types)')
            ->andWhere('r.deleted = false')
            ->setParameter('types', RecordType::variants(RecordType::LOCATION_FIX->value))
            ->orderBy('r.startTime', 'ASC');

        if (null !== $closestToId) {
            $recordsQuery->andWhere('r.closestToId = :closestToId')->setParameter('closestToId', $closestToId);
        }

        if ($view === 'A') {
            // All-time isn't tied to a navigable date — every live
            // LocationFix record ever, no bounds.
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

        $namesById = [];
        foreach ($this->namedLocations->findAll() as $place) {
            $namesById[$place->getId()] = $place->getName();
        }

        $points = array_values(array_filter(array_map(
            static function (Record $r) use ($sofiaTz, $namesById): ?array {
                if (null === $r->getLatitude() || null === $r->getLongitude()) {
                    return null;
                }

                if (null !== $r->getAccuracyMeters() && $r->getAccuracyMeters() >= self::MAX_USEFUL_ACCURACY_METERS) {
                    return null;
                }

                return [
                    'lat' => $r->getLatitude(),
                    'lon' => $r->getLongitude(),
                    'time' => $r->getStartTime()->setTimezone($sofiaTz)->format('M j, H:i:s'),
                    'accuracy' => $r->getAccuracyMeters(),
                    'closestTo' => null !== $r->getClosestToId() ? ($namesById[$r->getClosestToId()] ?? null) : null,
                ];
            },
            $records,
        )));
        $hiddenCount = count($records) - count($points);

        return $this->render('admin/location.html.twig', [
            'points' => $points,
            'fixCount' => count($points),
            'hiddenCount' => $hiddenCount,
            'currentDate' => $baseDate->format('Y-m-d'),
            'prevDate' => $prevDate->format('Y-m-d'),
            'nextDate' => $nextDate->format('Y-m-d'),
            'currentView' => $view,
            'dateDisplayString' => $dateDisplayString,
            'namedLocations' => $namesById,
            'currentClosestTo' => $closestToId,
            // EasyAdmin requirements
            'ea' => $adminContext,
            'dashboard_controller_class' => DashboardController::class,
        ]);
    }

    private function calculateAdjacentDate(\DateTimeImmutable $date, string $view, int $direction): \DateTimeImmutable
    {
        $modifier = $direction > 0 ? '+' : '-';

        return match ($view) {
            'Y' => $date->modify($modifier.'1 year'),
            'W' => $date->modify($modifier.'1 week'),
            'M' => $date->modify($modifier.'1 month'),
            default => $date->modify($modifier.'1 day'),
        };
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            DashboardController::class => DashboardController::class,
        ]);
    }
}
