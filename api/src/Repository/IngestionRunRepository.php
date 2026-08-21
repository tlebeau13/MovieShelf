<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\IngestionRun;
use App\Enum\IngestionSource;
use App\Enum\IngestionStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\Query\ResultSetMappingBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<IngestionRun>
 */
class IngestionRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, IngestionRun::class);
    }

    /**
     * @return list<IngestionRun>
     */
    public function findRecent(?IngestionSource $source = null, int $limit = 20): array
    {
        $qb = $this->createQueryBuilder('r')
            ->orderBy('r.startedAt', 'DESC')
            ->setMaxResults($limit);

        if (null !== $source) {
            $qb->andWhere('r.source = :source')->setParameter('source', $source);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * The last attempt per source — the "did last night work?" query. `DISTINCT ON`
     * rather than a query per source, so adding a fourth source costs nothing.
     *
     * The `id` tie-break is load-bearing: `started_at` is TIMESTAMP(0), so two
     * attempts in the same second are indistinguishable by time alone, and a retry
     * lands well inside one second.
     *
     * A source that has never run is absent from the result, which is the honest
     * answer rather than a fabricated empty row.
     *
     * @return array<string, IngestionRun>
     */
    public function findLatestPerSource(): array
    {
        $rsm = new ResultSetMappingBuilder($this->getEntityManager());
        $rsm->addRootEntityFromClassMetadata(IngestionRun::class, 'r');

        $sql = \sprintf(
            'SELECT DISTINCT ON (r.source) %s FROM raw.ingestion_run r ORDER BY r.source, r.started_at DESC, r.id DESC',
            $rsm->generateSelectClause(['r' => 'r']),
        );

        $latest = [];

        foreach ($this->getEntityManager()->createNativeQuery($sql, $rsm)->getResult() as $run) {
            $latest[$run->getSource()->value] = $run;
        }

        return $latest;
    }

    /**
     * Attempts left in Running past the point where they could still be running —
     * a worker killed mid-handler, since nothing rolls the row back. `$before` is
     * the caller's judgement of "too old", because that depends on the cadence.
     *
     * @return list<IngestionRun>
     */
    public function findStale(\DateTimeImmutable $before): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.status = :running')
            ->andWhere('r.startedAt < :before')
            ->setParameter('running', IngestionStatus::Running)
            ->setParameter('before', $before)
            ->orderBy('r.startedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
