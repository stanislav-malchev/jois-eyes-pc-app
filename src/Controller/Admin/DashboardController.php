<?php

namespace App\Controller\Admin;

use App\Entity\NamedLocation;
use App\Repository\RecordRepository;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

/**
 * Intentionally not covered by the /v1/* auth subscriber — see CLAUDE.md.
 */
#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly RecordRepository $records,
    ) {
    }

    public function index(): Response
    {
        $since24h = (new \DateTimeImmutable())->modify('-24 hours');

        return $this->render('admin/dashboard.html.twig', [
            'totalRecords' => $this->records->countAll(),
            'deletedRecords' => $this->records->countDeleted(),
            'last24h' => $this->records->countSince($since24h),
            'bySource' => $this->records->countGroupedBy('source'),
            'byType' => $this->records->countGroupedBy('type'),
            'latestReceivedAt' => $this->records->latestReceivedAt(),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('JoisEyes');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-home');
        yield MenuItem::linkToRoute('Heart Rate', 'fa fa-heart-pulse', 'admin_heart_rate');
        yield MenuItem::linkToRoute('Sleep', 'fa fa-bed', 'admin_sleep');
        yield MenuItem::linkToRoute('Steps', 'fa fa-shoe-prints', 'admin_steps');
        yield MenuItem::linkTo(NamedLocationCrudController::class, 'My Places', 'fa fa-map-marker-alt');
        /*yield MenuItem::linkTo(RecordCrudController::class, 'Location', 'fa fa-location-dot')
            ->setQueryParameter('filters[source][comparison]', '=')
            ->setQueryParameter('filters[source][value]', 'location');
        yield MenuItem::linkTo(RecordCrudController::class, 'Health Connect', 'fa fa-heart-pulse')
            ->setQueryParameter('filters[source][comparison]', '=')
            ->setQueryParameter('filters[source][value]', 'health_connect');*/
        yield MenuItem::linkTo(RecordCrudController::class, 'All records', 'fa fa-list')
            ->setQueryParameter('filters[deleted]', 0);
//            ->setQueryParameter('filters[deleted][value]', 'health_connect');

    }
}
