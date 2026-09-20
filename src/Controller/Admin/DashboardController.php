<?php

namespace App\Controller\Admin;

use App\Entity\NamedLocation;
use App\Repository\RecordRepository;
use App\Service\Finance\FinanceReportService;
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
        private readonly FinanceReportService $financeReportService,
    ) {
    }

    public function index(): Response
    {
        $since24h = (new \DateTimeImmutable())->modify('-24 hours');
        $financeStats = $this->financeReportService->generateAccountabilityReport();

        return $this->render('admin/dashboard.html.twig', [
            'totalRecords' => $this->records->countAll(),
            'deletedRecords' => $this->records->countDeleted(),
            'last24h' => $this->records->countSince($since24h),
            'bySource' => $this->records->countGroupedBy('source'),
            'byType' => $this->records->countGroupedBy('type'),
            'latestReceivedAt' => $this->records->latestReceivedAt(),
            'financeStats' => $financeStats,
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
        yield MenuItem::linkToRoute('Location', 'fa fa-location-dot', 'admin_location');
        yield MenuItem::section('MCP Tools', 'fa fa-robot');
        yield MenuItem::linkToRoute('Current State', 'fa fa-eye', 'admin_current_state');
        yield MenuItem::linkToRoute('PC State', 'fa fa-desktop', 'admin_pc_state');
        yield MenuItem::section('Settings', 'fa fa-gear');
        yield MenuItem::linkTo(NamedLocationCrudController::class, 'My Places', 'fa fa-map-marker-alt');
        yield MenuItem::linkTo(NamedNetworkCrudController::class, 'My Networks', 'fa fa-wifi');
        yield MenuItem::linkTo(NamedBluetoothDeviceCrudController::class, 'My BT Devices', 'fa fa-bluetooth-b');
        yield MenuItem::linkTo(RecordCrudController::class, 'All records', 'fa fa-list')
            ->setQueryParameter('filters[deleted]', 0);

        yield MenuItem::section('Finance', 'fa fa-money-bill-wave');
        yield MenuItem::linkTo(TransactionCrudController::class, 'Transactions', 'fa fa-exchange-alt');
        yield MenuItem::linkTo(ReceiptCrudController::class, 'Receipts', 'fa fa-receipt');
        yield MenuItem::linkTo(ProductCrudController::class, 'Products', 'fa fa-shopping-basket');
        yield MenuItem::linkTo(CategoryCrudController::class, 'Categories', 'fa fa-tags');
        yield MenuItem::linkTo(LineItemCrudController::class, 'Line Items', 'fa fa-list-ul');
    }
}
