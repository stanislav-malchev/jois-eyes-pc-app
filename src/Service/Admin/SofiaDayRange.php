<?php

namespace App\Service\Admin;

/**
 * Shared by the admin day/week/month chart pages (Steps/HeartRate/Sleep
 * controllers) — factored out fixing a real bug found 29.08.2026: each
 * controller computed its query range as naive UTC midnight-to-midnight on
 * `startTime`, but a full-day rollup like Samsung Health's Steps record
 * starts at 21:00 UTC the *previous* day (to span one Europe/Sofia calendar
 * day) — so asking for "Aug 28" matched zero rows; that row's UTC
 * timestamp falls on the 27th. Every other day-boundary computation in this
 * app (DailyStepsConsolidator, GetLastDayStepsTool, DailyVitalsSummaryTool)
 * already uses Europe/Sofia; this brings the admin views in line.
 */
final class SofiaDayRange
{
    public const TIMEZONE = 'Europe/Sofia';

    /**
     * @param \DateTimeImmutable $sofiaDate any time on the target calendar day, in Europe/Sofia
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable, 2: string} [startUtc, endUtc, displayString]
     */
    public static function forView(\DateTimeImmutable $sofiaDate, string $view): array
    {
        switch ($view) {
            case 'W':
                $start = $sofiaDate->modify('monday this week')->setTime(0, 0, 0);
                $end = $start->modify('+6 days')->setTime(23, 59, 59);
                $display = sprintf('Week of %s', $start->format('M d, Y'));
                break;
            case 'M':
                $start = $sofiaDate->modify('first day of this month')->setTime(0, 0, 0);
                $end = $sofiaDate->modify('last day of this month')->setTime(23, 59, 59);
                $display = $start->format('F Y');
                break;
            case 'D':
            default:
                $start = $sofiaDate->setTime(0, 0, 0);
                $end = $sofiaDate->setTime(23, 59, 59);
                $display = $start->format('F d, Y');
                break;
        }

        $utc = new \DateTimeZone('UTC');

        return [$start->setTimezone($utc), $end->setTimezone($utc), $display];
    }
}
