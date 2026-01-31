<?php

namespace App\Repository;

use App\Entity\Rate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Client>
 */
class RateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Rate::class);
    }

    public function findOneByPairAndDate(string $source, string $target, \DateTimeImmutable $validFrom): ?Rate
    {
        return $this->findOneBy([
            'sourceCurrency' => strtoupper($source),
            'targetCurrency' => strtoupper($target),
            'validFrom' => $validFrom,
        ]);
    }

    public function findApplicableRate(string $source, string $target, \DateTimeInterface $date): ?Rate
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.sourceCurrency = :s')
            ->andWhere('r.targetCurrency = :t')
            ->andWhere('r.validFrom <= :d')
            ->setParameter('s', strtoupper($source))
            ->setParameter('t', strtoupper($target))
            ->setParameter('d', $date->format('Y-m-d')) // validFrom to DATE
            ->orderBy('r.validFrom', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
