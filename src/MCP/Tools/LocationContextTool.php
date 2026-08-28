<?php

namespace App\MCP\Tools;

use App\Backdoor\LiveVitalsResolver;
use KLP\KlpMcpServer\Services\ProgressService\ProgressNotifierInterface;
use KLP\KlpMcpServer\Services\ToolService\Annotation\ToolAnnotation;
use KLP\KlpMcpServer\Services\ToolService\Result\StructuredToolResult;
use KLP\KlpMcpServer\Services\ToolService\Result\ToolResultInterface;
use KLP\KlpMcpServer\Services\ToolService\Schema\StructuredSchema;
use KLP\KlpMcpServer\Services\ToolService\StreamableToolInterface;

/**
 * Reports whether the phone's last known fix is near home, using a radius
 * generous enough to absorb typical GPS drift (fixes in this dataset carry
 * up to ~100m of reported accuracy).
 */
class LocationContextTool implements StreamableToolInterface
{
    private const NEAR_HOME_RADIUS_KM = 0.5;

    public function __construct(
        private readonly LiveVitalsResolver $vitals,
        private readonly float $homeLatitude,
        private readonly float $homeLongitude,
    ) {
    }

    public function getName(): string
    {
        return 'location-context';
    }

    public function getDescription(): string
    {
        return 'Checks if the user is currently near home or elsewhere. Prefers a live read from the phone; falls back to the last stored record if the phone is unreachable.';
    }

    public function getInputSchema(): StructuredSchema
    {
        return new StructuredSchema;
    }

    public function getOutputSchema(): ?StructuredSchema
    {
        return null;
    }

    public function getAnnotations(): ToolAnnotation
    {
        return new ToolAnnotation(
            title: 'Location context',
            readOnlyHint: true,
            destructiveHint: false,
            idempotentHint: true,
            openWorldHint: false,
        );
    }

    public function execute(array $arguments): ToolResultInterface
    {
        $fix = $this->vitals->getLocation();

        if (!$fix) {
            return new StructuredToolResult([
                'status' => 'unknown',
                'distance_from_home_km' => null,
                'last_updated' => null,
            ]);
        }

        $distanceKm = $this->haversineKm(
            $this->homeLatitude,
            $this->homeLongitude,
            $fix['latitude'],
            $fix['longitude'],
        );

        return new StructuredToolResult([
            'status' => $distanceKm <= self::NEAR_HOME_RADIUS_KM ? 'near_home' : 'away',
            'source' => $fix['source'],
            'distance_from_home_km' => round($distanceKm, 2),
            'last_updated' => $this->relativeTime($fix['timestamp']),
        ]);
    }

    public function isStreaming(): bool
    {
        return false;
    }

    public function setProgressNotifier(ProgressNotifierInterface $progressNotifier): void
    {
    }

    private function haversineKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadiusKm = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;

        return $earthRadiusKm * 2 * atan2(sqrt($a), sqrt(1 - $a));
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
}
