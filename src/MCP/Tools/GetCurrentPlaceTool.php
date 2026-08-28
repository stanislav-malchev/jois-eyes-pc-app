<?php

namespace App\MCP\Tools;

use App\Backdoor\LiveVitalsResolver;
use App\Repository\NamedLocationRepository;
use KLP\KlpMcpServer\Services\ProgressService\ProgressNotifierInterface;
use KLP\KlpMcpServer\Services\ToolService\Annotation\ToolAnnotation;
use KLP\KlpMcpServer\Services\ToolService\Result\StructuredToolResult;
use KLP\KlpMcpServer\Services\ToolService\Result\ToolResultInterface;
use KLP\KlpMcpServer\Services\ToolService\Schema\StructuredSchema;
use KLP\KlpMcpServer\Services\ToolService\StreamableToolInterface;
use Location\Coordinate;
use Location\Distance\Haversine;

class GetCurrentPlaceTool implements StreamableToolInterface
{
    public function __construct(
        private readonly LiveVitalsResolver $vitals,
        private readonly NamedLocationRepository $namedLocations,
    ) {
    }

    public function getName(): string
    {
        return 'get_current_place';
    }

    public function getDescription(): string
    {
        return 'Returns the current named place where Stan is, based on his latest GPS coordinates and defined geofences. Prefers a live read from the phone; falls back to the last stored record if the phone is unreachable.';
    }

    public function getInputSchema(): StructuredSchema
    {
        return new StructuredSchema();
    }

    public function getOutputSchema(): ?StructuredSchema
    {
        return null;
    }

    public function getAnnotations(): ToolAnnotation
    {
        return new ToolAnnotation(
            title: 'Get current place',
            readOnlyHint: true,
            idempotentHint: true,
        );
    }

    public function execute(array $arguments): ToolResultInterface
    {
        $fix = $this->vitals->getLocation();

        if (!$fix) {
            return new StructuredToolResult([
                'status' => 'no_data',
                'formatted_location' => 'No location data available.',
            ]);
        }

        $currentCoord = new Coordinate($fix['latitude'], $fix['longitude']);
        $places = $this->namedLocations->findAll();
        $haversine = new Haversine();

        $nearestPlace = null;
        $minDistance = null;

        foreach ($places as $place) {
            $placeCoord = new Coordinate($place->getLatitude(), $place->getLongitude());
            $distance = $haversine->getDistance($currentCoord, $placeCoord);

            if ($distance <= $place->getRadiusMeters()) {
                return $this->buildResult($place->getName(), $distance, $fix['timestamp'], $fix['source'], true);
            }

            if ($minDistance === null || $distance < $minDistance) {
                $minDistance = $distance;
                $nearestPlace = $place;
            }
        }

        if ($nearestPlace) {
            return $this->buildResult($nearestPlace->getName(), $minDistance, $fix['timestamp'], $fix['source'], false);
        }

        return new StructuredToolResult([
            'status' => 'unknown',
            'source' => $fix['source'],
            'formatted_location' => 'Outside all defined radii and no nearest place found.',
            'last_updated' => $this->relativeTime($fix['timestamp']),
        ]);
    }

    private function buildResult(string $name, float $distance, \DateTimeImmutable $time, string $source, bool $isMatch): StructuredToolResult
    {
        $ageSeconds = (new \DateTimeImmutable())->getTimestamp() - $time->getTimestamp();

        $formattedLocation = $isMatch
            ? sprintf('Stan is at %s', $name)
            : sprintf('Stan is near %s (%d meters away)', $name, round($distance));

        return new StructuredToolResult([
            'source' => $source,
            'formatted_location' => $formattedLocation,
            'timestamp' => $time->format(\DateTimeInterface::ATOM),
            'relative_age' => $this->relativeTime($time),
            'is_stale' => $ageSeconds > 3600, // 60 minutes
        ]);
    }

    private function relativeTime(\DateTimeImmutable $time): string
    {
        $seconds = (new \DateTimeImmutable())->getTimestamp() - $time->getTimestamp();

        if ($seconds < 60) {
            return 'just now';
        }
        if ($seconds < 3600) {
            $minutes = intdiv($seconds, 60);
            return sprintf('%d min%s ago', $minutes, 1 === $minutes ? '' : 's');
        }
        if ($seconds < 86400) {
            $hours = intdiv($seconds, 3600);
            return sprintf('%d hour%s ago', $hours, 1 === $hours ? '' : 's');
        }
        $days = intdiv($seconds, 86400);
        return sprintf('%d day%s ago', $days, 1 === $days ? '' : 's');
    }

    public function isStreaming(): bool
    {
        return false;
    }

    public function setProgressNotifier(ProgressNotifierInterface $progressNotifier): void
    {
    }
}
