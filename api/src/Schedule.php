<?php

namespace App;

use App\Message\IngestNytWeek;
use Symfony\Component\Scheduler\Attribute\AsSchedule;
use Symfony\Component\Scheduler\RecurringMessage;
use Symfony\Component\Scheduler\Schedule as SymfonySchedule;
use Symfony\Component\Scheduler\ScheduleProviderInterface;
use Symfony\Contracts\Cache\CacheInterface;

#[AsSchedule]
class Schedule implements ScheduleProviderInterface
{
    public function __construct(
        private CacheInterface $cache,
    ) {
    }

    public function getSchedule(): SymfonySchedule
    {
        return (new SymfonySchedule())
            ->stateful($this->cache) // ensure missed tasks are executed
            ->processOnlyLastMissedRun(true) // ensure only last missed task is run

            ->add(
                // Weekly from a Thursday morning in New York (#6). NYT dates a list to
                // a Sunday but publishes it days earlier, so a Thursday tick reliably
                // picks up the list dated the coming Sunday. The anchor is in NYT's
                // timezone rather than the container's UTC because that is what the
                // publication follows.
                //
                // An anchored interval rather than a cron expression, which would pull
                // in dragonmantank/cron-expression for one line: "every 7 days from
                // this instant" says the same thing here, and the schedule is stateful,
                // so a restart resumes from the checkpoint instead of re-firing.
                //
                // Fetching the same list twice is harmless — the write is an upsert
                // keyed on the list week — so the cadence is chosen to never miss,
                // not to never repeat.
                RecurringMessage::every(
                    '1 week',
                    new IngestNytWeek('hardcover-fiction'),
                    from: new \DateTimeImmutable('2026-08-20 06:00:00', new \DateTimeZone('America/New_York')),
                ),
            )
        ;
    }
}
