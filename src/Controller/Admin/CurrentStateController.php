<?php

namespace App\Controller\Admin;

use App\Service\CurrentState\CurrentStateResolver;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\EA;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Factory\AdminContextFactory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin page showing exactly what the `current-state` MCP tool and
 * `GET /api/v1/current-state` return — same App\Service\CurrentState\
 * CurrentStateResolver, same live-first phone poll, same decorated JSON,
 * nothing reshaped for display. The point: if Stan asks his agent "what
 * do you see?", the JSON on this page is the JSON she's reading.
 */
class CurrentStateController extends AbstractController
{
    public function __construct(
        private readonly CurrentStateResolver $resolver,
        private readonly AdminContextFactory $adminContextFactory,
    ) {
    }

    #[Route('/admin/current-state', name: 'admin_current_state')]
    public function __invoke(Request $request, ?AdminContext $adminContext): Response
    {
        if (null === $adminContext) {
            $dashboardController = $this->container->get(DashboardController::class);
            $adminContext = $this->adminContextFactory->create($request, $dashboardController, null, null);
            $request->attributes->set(EA::CONTEXT_REQUEST_ATTRIBUTE, $adminContext);
            $request->attributes->set('ea', $adminContext);
        }

        $data = $this->resolver->resolve();

        return $this->render('admin/current_state.html.twig', [
            'ea' => $adminContext,
            'json' => json_encode($data, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
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
