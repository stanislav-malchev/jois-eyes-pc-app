<?php

namespace App\Controller\Admin;

use App\Service\PcState\PcStateResolver;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Factory\AdminContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin page showing exactly what the `pc-state` MCP tool and
 * `GET /api/v1/pc-state` return — sibling of App\Controller\Admin\
 * CurrentStateController, same App\Service\PcState\PcStateResolver, no
 * reshaping for display.
 */
class PcStateController extends AbstractController
{
    public function __construct(
        private readonly PcStateResolver $resolver,
        private readonly AdminContextFactory $adminContextFactory,
    ) {
    }

    #[Route('/admin/pc-state', name: 'admin_pc_state')]
    public function __invoke(Request $request, ?AdminContext $adminContext): Response
    {
        if (null === $adminContext) {
            $dashboardController = $this->container->get(DashboardController::class);
            $adminContext = $this->adminContextFactory->create($request, $dashboardController, null, null);
            $request->attributes->set(EA::CONTEXT_REQUEST_ATTRIBUTE, $adminContext);
            $request->attributes->set('ea', $adminContext);
        }

        $data = $this->resolver->resolve();

        return $this->render('admin/pc_state.html.twig', [
            'ea' => $adminContext,
            'json' => json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
            'reachable' => $data['reachable'] ?? null,
            'verdict' => $data['verdict'] ?? null,
            'serverTime' => $data['server_time']['iso'] ?? null,
        ]);
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            DashboardController::class => DashboardController::class,
        ]);
    }
}
