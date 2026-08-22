<?php

namespace App\Controller;

use App\Enum\RecordType;
use App\Repository\RecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Manual smoke test: visit in a browser and it inserts one record, so you
 * can confirm the ingest pipeline (routing -> repository -> SQLite) works
 * without crafting a POST request.
 */
class PingController
{
    #[Route('/v1/ping', name: 'v1_ping', methods: ['GET'])]
    public function ping(RecordRepository $records, EntityManagerInterface $em): Response
    {
        $now = new \DateTimeImmutable();
        $uid = sprintf('ping-%s-%s', $now->format('Ymd-His'), bin2hex(random_bytes(3)));

        $records->upsertByRecordUid(
            $uid,
            'debug',
            RecordType::PING->value,
            $now,
            null,
            $now,
            $now,
            false,
            ['note' => 'manual browser ping'],
        );
        $em->flush();

        return new Response(
            sprintf("OK\ninserted: %s\ntotal records stored: %d\n", $uid, $records->countAll()),
            Response::HTTP_OK,
            ['Content-Type' => 'text/plain'],
        );
    }
}
