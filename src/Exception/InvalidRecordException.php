<?php

namespace App\Exception;

/**
 * Thrown when a single record in an ingest batch fails validation. Caught
 * per-record by IngestController so one bad record cannot fail the batch.
 */
class InvalidRecordException extends \RuntimeException
{
}
