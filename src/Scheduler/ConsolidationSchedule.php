<?php

namespace App\Scheduler;

use App\Scheduler\Message\ConsolidateMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * Phase 2 of the "consolidation" plan (LLM wiki concepts/consolidation.md):
 * every 30 minutes, check records synced since the last tick for
 * duplicates/overlaps against their neighbors — maintenance, not
 * freshness, hence much slower than BreathSchedule's 3-minute cadence.
 *
 * Run the worker with: php bin/console messenger:consume scheduler_consolidation
 */
#[AsSchedule('consolidation')]
class ConsolidationSchedule implements ScheduleProviderInterface
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('30 minutes', new ConsolidateMessage()))
            ->stateful($this->cache);
    }
}
