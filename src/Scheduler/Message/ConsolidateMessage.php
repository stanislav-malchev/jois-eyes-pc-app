<?php

namespace App\Scheduler\Message;

/**
 * Marker message: "it's time to check recently-synced records for
 * duplicates/overlaps." Carries no data — the handler reads its own cursor
 * state, there's nothing to schedule ahead of time.
 */
final class ConsolidateMessage
{
}
