<?php

namespace App\Tests\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class SyncApiTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $schemaTool = new SchemaTool($em);
        $metadata = $em->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testHealthReportsRecordCount(): void
    {
        $client = $this->client;
        $client->request('GET', '/v1/health');

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('ok', $body['status']);
        self::assertSame(0, $body['recordsStored']);
    }

    public function testIngestNewRecordIsAcked(): void
    {
        $client = $this->client;
        $client->request(
            'POST',
            '/v1/ingest',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->envelope([$this->record('uid-1')])),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(['uid-1'], $body['ackedUids']);
        self::assertSame([], $body['rejected']);
    }

    public function testIngestSameUidTwiceIsIdempotent(): void
    {
        $client = $this->client;
        $envelope = json_encode($this->envelope([$this->record('uid-1')]));

        $client->request('POST', '/v1/ingest', server: ['CONTENT_TYPE' => 'application/json'], content: $envelope);
        $client->request('POST', '/v1/ingest', server: ['CONTENT_TYPE' => 'application/json'], content: $envelope);

        $client->request('GET', '/v1/health');
        $health = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(1, $health['recordsStored']);
    }

    public function testIngestTombstoneMarksRecordDeletedWithoutRemovingIt(): void
    {
        $client = $this->client;
        $client->request('POST', '/v1/ingest', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($this->envelope([$this->record('uid-1')])));

        $tombstone = $this->record('uid-1');
        $tombstone['payload'] = ['deleted' => true];
        $client->request('POST', '/v1/ingest', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($this->envelope([$tombstone])));

        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(['uid-1'], $body['ackedUids']);

        $client->request('GET', '/v1/health');
        $health = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(1, $health['recordsStored']);
    }

    public function testIngestAcceptsUnknownType(): void
    {
        $client = $this->client;
        $record = $this->record('uid-unknown');
        $record['type'] = 'SomeFutureHcType';
        $record['payload'] = ['whatever' => 'goes here'];

        $client->request('POST', '/v1/ingest', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($this->envelope([$record])));

        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(['uid-unknown'], $body['ackedUids']);
    }

    public function testIngestRejectsInvalidRecordWithoutFailingBatch(): void
    {
        $client = $this->client;
        $bad = $this->record('uid-bad');
        unset($bad['startTime']);

        $client->request(
            'POST',
            '/v1/ingest',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($this->envelope([$this->record('uid-good'), $bad])),
        );

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(['uid-good'], $body['ackedUids']);
        self::assertCount(1, $body['rejected']);
        self::assertSame('uid-bad', $body['rejected'][0]['recordUid']);
    }

    public function testIngestWithEmptyRecordsIsAcceptedAsNoOp(): void
    {
        $client = $this->client;
        $client->request('POST', '/v1/ingest', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($this->envelope([])));

        self::assertSame(200, $client->getResponse()->getStatusCode());
        $body = json_decode($client->getResponse()->getContent(), true);
        self::assertSame([], $body['ackedUids']);
        self::assertSame([], $body['rejected']);
    }

    public function testIngestMalformedJsonIsBadRequest(): void
    {
        $client = $this->client;
        $client->request('POST', '/v1/ingest', server: ['CONTENT_TYPE' => 'application/json'], content: '{not json');

        self::assertSame(400, $client->getResponse()->getStatusCode());
    }

    public function testPingInsertsARecord(): void
    {
        $client = $this->client;
        $client->request('GET', '/v1/ping');

        self::assertSame(200, $client->getResponse()->getStatusCode());

        $client->request('GET', '/v1/health');
        $health = json_decode($client->getResponse()->getContent(), true);
        self::assertSame(1, $health['recordsStored']);
    }

    private function envelope(array $records): array
    {
        return [
            'sentAt' => '2026-08-20T07:20:11Z',
            'records' => $records,
        ];
    }

    private function record(string $uid): array
    {
        return [
            'recordUid' => $uid,
            'source' => 'health_connect',
            'type' => 'HeartRate',
            'startTime' => '2026-08-20T06:00:00Z',
            'endTime' => '2026-08-20T06:05:00Z',
            'ingestedAt' => '2026-08-20T06:16:02Z',
            'payload' => ['samples' => [['time' => '2026-08-20T06:01:00Z', 'beatsPerMinute' => 62]]],
        ];
    }
}
