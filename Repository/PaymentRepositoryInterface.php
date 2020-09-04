<?php

namespace Andchir\OmnipayBundle\Repository;

use Doctrine\Persistence\ObjectRepository;

interface PaymentRepositoryInterface extends ObjectRepository {

    public function findLastById($paymentId, $seconds = 30 * 60, $date_timezone = null);

    public function findLastByEmail($email, $seconds = 30 * 60, $date_timezone = null);

}
