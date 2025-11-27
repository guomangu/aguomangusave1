<?php

namespace App\Repository;

use App\Entity\AgendaSlotPattern;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AgendaSlotPattern>
 */
class AgendaSlotPatternRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AgendaSlotPattern::class);
    }
}


