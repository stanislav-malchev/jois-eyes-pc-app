<?php

namespace App\Scheduler\Message;

/**
 * Marker message: "it's time to poll the phone." Carries no data — the
 * handler always reacts to current state, so there's nothing to schedule
 * ahead of time.
 */
final class PollBackdoorMessage
{
}
