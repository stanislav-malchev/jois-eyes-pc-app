<?php

namespace App\Controller;

use App\Service\PcState\PcStateResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Plain-HTTP view of App\MCP\Tools\PcStateTool's exact same document
 * (App\Service\PcState\PcStateResolver does the actual work; both callers
 * just adapt it) — sibling of App\Controller\CurrentStateController, same
 * `/api/v1/*` namespace (this app's own read API, distinct from the
 * phone-sync contract's `/v1/*` routes).
 */
class PcStateController
{
    #[Route('/api/v1/pc-state', name: 'api_v1_pc_state', methods: ['GET'])]
    public function __invoke(PcStateResolver $resolver): JsonResponse
    {
        return new JsonResponse($resolver->resolve());
    }
}
