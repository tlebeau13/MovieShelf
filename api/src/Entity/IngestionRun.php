<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\IngestionSource;
use App\Enum\IngestionStatus;
use App\Repository\IngestionRunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One attempt at pulling one window of one source (#29). #5, #6 and #7 all write
 * this shape, so "did last night's ingestion work?" is one query rather than three
 * log formats.
 *
 * A row is an *attempt*, not a logical run: when Messenger retries a failed handler
 * (#8) the new attempt gets its own row. Collapsing them would mean either losing
 * the failures or rewriting history on retry, and the failures are the interesting
 * part — the retry count in `messenger.yaml` is only defensible if you can see how
 * often it fires.
 */
#[ORM\Entity(repositoryClass: IngestionRunRepository::class)]
#[ORM\Table(name: 'ingestion_run', schema: 'raw')]
// Every read is "the last N for a source" or "the last N overall"; both walk this
// index backwards.
#[ORM\Index(name: 'idx_ingestion_run_source_started', columns: ['source', 'started_at'])]
// One live attempt per window, enforced by the database rather than by the caller
// remembering — a queue redelivers, so two concurrent runs over one window is a
// matter of when, not if. Null windows are exempt (every NULL is distinct in
// Postgres): a keyed source that wants the guarantee passes a window.
#[ORM\UniqueConstraint(
    name: 'uniq_ingestion_run_active',
    columns: ['source', 'window_start', 'window_end'],
    // Spelled the way PostgreSQL reports it back (pg_indexes.indexdef), not the way
    // it was written: the schema comparator matches this string literally, and
    // "(status = 'running')" reads as drift on every diff.
    options: ['where' => "((status)::text = 'running'::text)"],
)]
class IngestionRun
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::BIGINT)]
    private ?string $id = null;

    #[ORM\Column(type: Types::STRING, length: 32, enumType: IngestionSource::class)]
    private IngestionSource $source;

    #[ORM\Column(type: Types::STRING, length: 16, enumType: IngestionStatus::class)]
    private IngestionStatus $status = IngestionStatus::Running;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE)]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(type: Types::DATETIMETZ_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(type: Types::INTEGER, options: ['default' => 0])]
    private int $rowsWritten = 0;

    /**
     * The slice of source history this attempt covered, when the source is dated:
     * a NYT list week, a TMDB release window. Null for sources crawled by key
     * rather than by date (Open Library, #7). Both #6's resumable backfill and
     * "which weeks are missing" are queries over these two columns.
     */
    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $windowStart = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $windowEnd = null;

    /**
     * The exception's class, kept apart from its message so failures can be counted
     * by kind — "how many 503s this week" is a GROUP BY, not a LIKE over prose.
     * Null unless the status is Failed.
     */
    #[ORM\Column(type: Types::STRING, length: 255, nullable: true)]
    private ?string $errorClass = null;

    /** The exception's message, truncated. Never a stack trace. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $error = null;

    /**
     * Source-specific counters that are not worth a column each: requests made,
     * pages walked, 429s absorbed. Keeping them here is what stops #5/#6/#7 from
     * each growing their own columns on a shared table.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(type: Types::JSONB)]
    private array $details = [];

    public function __construct(IngestionSource $source, \DateTimeImmutable $startedAt)
    {
        $this->source = $source;
        $this->startedAt = $startedAt;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function getSource(): IngestionSource
    {
        return $this->source;
    }

    public function getStatus(): IngestionStatus
    {
        return $this->status;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getRowsWritten(): int
    {
        return $this->rowsWritten;
    }

    public function getWindowStart(): ?\DateTimeImmutable
    {
        return $this->windowStart;
    }

    public function getWindowEnd(): ?\DateTimeImmutable
    {
        return $this->windowEnd;
    }

    public function getErrorClass(): ?string
    {
        return $this->errorClass;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    /** Class and message together, for a log line or a table cell. */
    public function getErrorSummary(): ?string
    {
        if (null === $this->errorClass) {
            return $this->error;
        }

        return \sprintf('%s: %s', $this->errorClass, (string) $this->error);
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }

    public function setWindow(?\DateTimeImmutable $start, ?\DateTimeImmutable $end): self
    {
        $this->windowStart = $start;
        $this->windowEnd = $end;

        return $this;
    }

    /** @param array<string, mixed> $details */
    public function succeed(\DateTimeImmutable $finishedAt, int $rowsWritten, array $details = []): self
    {
        $this->status = IngestionStatus::Success;
        $this->finishedAt = $finishedAt;
        $this->rowsWritten = $rowsWritten;
        $this->details = $details + $this->details;

        return $this;
    }

    /** @param array<string, mixed> $details */
    public function fail(\DateTimeImmutable $finishedAt, string $errorClass, string $error, int $rowsWritten = 0, array $details = []): self
    {
        $this->status = IngestionStatus::Failed;
        $this->finishedAt = $finishedAt;
        $this->rowsWritten = $rowsWritten;
        $this->errorClass = $errorClass;
        $this->error = $error;
        $this->details = $details + $this->details;

        return $this;
    }

    /** Seconds the attempt took, or null while it is still Running. */
    public function getDuration(): ?float
    {
        if (null === $this->finishedAt) {
            return null;
        }

        return (float) $this->finishedAt->format('U.u') - (float) $this->startedAt->format('U.u');
    }
}
