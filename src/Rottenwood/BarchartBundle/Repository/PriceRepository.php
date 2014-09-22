<?php

namespace Rottenwood\BarchartBundle\Repository;

use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityRepository;

/**
 * PriceRepository
 * This class was generated by the Doctrine ORM. Add your own custom
 * repository methods below.
 */
class PriceRepository extends EntityRepository {

    /**
     * Поиск цены по ID, и ближайших от нее цен
     * @param $id
     * @param $limit
     * @return \Doctrine\Common\Collections\Collection
     */
    public function findPricesFromId($id, $limit) {
        if (!$id) $limit++;

        $expr = Criteria::expr();
        $criteria = Criteria::create();
        $criteria->where(
            $expr->andX(
                $expr->gte('id', $id),
                $expr->lt('id', ($id + $limit))
            )
        );
        return $this->matching($criteria);
    }
}
