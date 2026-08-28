<?php

namespace App\Scheduler;

use App\Scheduler\Message\PollBackdoorMessage;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

/**
 * "The breath": polls the phone's backdoor every 3 minutes — inside the
 * contract's documented 2-5 min daemon cadence (see the LLM wiki's
 * concepts/hc-pull.md and entities/the-breath.md) — so Health Connect data
 * stays fresher than the phone's own 15-min WorkManager floor allows.
 *
 * Run the worker with: php bin/console messenger:consume scheduler_breath
 */
#[AsSchedule('breath')]
class BreathSchedule implements ScheduleProviderInterface
{
    public function __construct(private readonly CacheInterface $cache)
    {
    }

    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->add(RecurringMessage::every('3 minutes', new PollBackdoorMessage()))
            // Persists next-run time across worker restarts so a restart
            // after downtime doesn't fire a burst of catch-up polls.
            ->stateful($this->cache);
    }
}
