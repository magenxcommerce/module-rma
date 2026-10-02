<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Service;

/**
 * A withdrawal declaration exactly as the consumer submitted it.
 *
 * Everything is free text: the declaration is valid as stated, whether or not
 * it names an existing order correctly. Matching it to the order, its items and
 * any return is staff work after the fact.
 */
class WithdrawalRequest
{
    /**
     * @param int $storeId Store view the declaration came in on
     * @param string $name Declarant's name
     * @param string $email Declarant's email (where the confirmation goes)
     * @param string $orderNumber Order number as typed
     * @param string $items Product names or SKUs as typed; empty means the whole order
     * @param string $message Optional free text
     * @param int|null $customerId Logged-in customer, when there is one
     */
    public function __construct(
        public readonly int $storeId,
        public readonly string $name,
        public readonly string $email,
        public readonly string $orderNumber,
        public readonly string $items,
        public readonly string $message,
        public readonly ?int $customerId = null
    ) {
    }
}
