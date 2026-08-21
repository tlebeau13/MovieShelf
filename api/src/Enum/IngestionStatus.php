<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Three states, deliberately. "Partial" is not one of them: a run that wrote some
 * rows and then threw is Failed, and how far it got is `rowsWritten` — otherwise
 * "did last night's ingestion work?" has an answer that needs interpreting.
 */
enum IngestionStatus: string
{
    /**
     * Still going, or the worker died mid-run. Nothing distinguishes the two from
     * the row alone: a Running row whose startedAt is older than the job's cadence
     * is a crash, and `app:ingestion:runs` flags it as such.
     */
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
}
