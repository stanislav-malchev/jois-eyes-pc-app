<?php

namespace App\Controller;

use App\Exception\InvalidRecordException;
use App\Repository\RecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Exception\JsonException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class IngestController
{
    #[Route('/v1/ingest', name: 'v1_ingest', methods: ['POST'])]
    public function ingest(Request $request, RecordRepository $records, EntityManagerInterface $em): Response
    {
        try {
            $body = $request->toArray();
        } catch (JsonException $e) {
            $this->logRejectedEnvelope($request->getContent(), $e->getMessage());

            return new JsonResponse(status: Response::HTTP_BAD_REQUEST);
        }

        if (!isset($body['records']) || !is_array($body['records'])) {
            $this->logRejectedEnvelope($request->getContent(), 'missing/invalid records');

            return new JsonResponse(status: Response::HTTP_BAD_REQUEST);
        }

        $receivedAt = new \DateTimeImmutable();
        $ackedUids = [];
        $rejected = [];

        foreach ($body['records'] as $raw) {
            $recordUid = is_array($raw) ? ($raw['recordUid'] ?? null) : null;

            try {
                if (!is_array($raw)) {
                    throw new InvalidRecordException('record is not an object');
                }
                $parsed = $this->parseRecord($raw);
            } catch (InvalidRecordException $e) {
                $rejected[] = [
                    'recordUid' => $recordUid ?? 'unknown',
                    'reason' => $e->getMessage(),
                ];
                $this->logRejectedRecord($recordUid ?? 'unknown', $raw, $e->getMessage());
                continue;
            }

            $records->upsertByRecordUid(
                $parsed['recordUid'],
                $parsed['source'],
                $parsed['type'],
                $parsed['startTime'],
                $parsed['endTime'],
                $parsed['ingestedAt'],
                $receivedAt,
                $parsed['deleted'],
                $parsed['payload'],
            );
            $ackedUids[] = $parsed['recordUid'];
        }

        $em->flush();

        return new JsonResponse([
            'ackedUids' => $ackedUids,
            'rejected' => $rejected,
        ]);
    }

    /**
     * Batch-level rejections (bad JSON / missing envelope fields) can't be
     * retried by the phone, so log the raw body loudly for debugging — see
     * var/log/ingest_rejected.log.
     */
    private function logRejectedEnvelope(string $rawBody, string $reason): void
    {
        $line = sprintf(
            "[%s] %s\n%s\n\n",
            (new \DateTimeImmutable())->format('c'),
            $reason,
            $rawBody,
        );
        file_put_contents(\dirname(__DIR__, 2).'/var/log/ingest_rejected.log', $line, FILE_APPEND);
    }

    /**
     * Per-record rejections don't fail the batch (the phone gets a 200 with
     * this record absent from ackedUids and present in `rejected`), so
     * unlike logRejectedEnvelope() there's no HTTP-level signal that
     * anything went wrong — log it here or it's invisible from the server
     * side entirely.
     */
    private function logRejectedRecord(mixed $recordUid, mixed $raw, string $reason): void
    {
        $line = sprintf(
            "[%s] record rejected: %s (recordUid=%s)\n%s\n\n",
            (new \DateTimeImmutable())->format('c'),
            $reason,
            is_string($recordUid) ? $recordUid : json_encode($recordUid),
            json_encode($raw),
        );
        file_put_contents(\dirname(__DIR__, 2).'/var/log/ingest_rejected.log', $line, FILE_APPEND);
    }

    /**
     * @throws InvalidRecordException
     */
    private function parseRecord(array $data): array
    {
        foreach (['recordUid', 'source', 'type', 'startTime', 'ingestedAt'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field]) || '' === $data[$field]) {
                throw new InvalidRecordException(sprintf('missing or invalid field: %s', $field));
            }
        }

        if (!isset($data['payload']) || !is_array($data['payload'])) {
            throw new InvalidRecordException('missing or invalid field: payload');
        }

        try {
            $startTime = new \DateTimeImmutable($data['startTime']);
            $ingestedAt = new \DateTimeImmutable($data['ingestedAt']);
            $endTime = isset($data['endTime']) ? new \DateTimeImmutable($data['endTime']) : null;
        } catch (\Exception) {
            throw new InvalidRecordException('invalid timestamp');
        }

        return [
            'recordUid' => $data['recordUid'],
            'source' => $data['source'],
            'type' => $data['type'],
            'startTime' => $startTime,
            'endTime' => $endTime,
            'ingestedAt' => $ingestedAt,
            'payload' => $data['payload'],
            'deleted' => ['deleted' => true] === $data['payload'],
        ];
    }
}
