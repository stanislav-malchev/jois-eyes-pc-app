<?php

namespace App\Controller;

use App\Service\CurrentState\CurrentStateResolver;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Plain-HTTP view of App\MCP\Tools\CurrentStateTool's exact same document
 * (App\Service\CurrentState\CurrentStateResolver does the actual work;
 * both callers just adapt it). Added 29.08.2026 so Stan can poll this the
 * same way he already polls the phone's own raw `/v1/snapshot` — with
 * curl, a browser, or docs/requests/test_current_state.http — without
 * needing an MCP client to see exactly what the tool an agent calls
 * actually returns: the phone's whole snapshot, plus our decorations,
 * nothing chopped out.
 *
 * Under `/api/v1/*`, not `/v1/*` — deliberately a different namespace
 * from the phone-sync contract routes (`/v1/ingest`, `/v1/health`,
 * `/v1/ping`) so this isn't mistaken for part of that contract; this is
 * this app's own read API.
 */
class CurrentStateController
{
    #[Route('/api/v1/current-state', name: 'api_v1_current_state', methods: ['GET'])]
    public function __invoke(CurrentStateResolver $resolver): JsonResponse
    {
        return new JsonResponse($resolver->resolve());
    }
}
