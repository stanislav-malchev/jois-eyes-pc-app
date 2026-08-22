<?php

namespace App\Controller;

use App\Repository\RecordRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

class HealthController
{
    #[Route('/v1/health', name: 'v1_health', methods: ['GET'])]
    public function health(RecordRepository $records): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'time' => (new \DateTimeImmutable())->format('Y-m-d\TH:i:s\Z'),
            'recordsStored' => $records->countAll(),
        ]);
    }
}
