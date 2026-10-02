<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Service;

use Magenx\Rma\Api\Data\RMAInterface;

/**
 * What WithdrawalService::submit() did with one declaration. Any review reason
 * means staff must look at it; the declaration itself stands either way.
 */
class WithdrawalResult
{
    /** Withdrawal RMAs are switched off (or the RMA module is); nothing was created. */
    const REVIEW_DISABLED = 'disabled';
    /** Declared after the period (last shipment + transit + period days). */
    const REVIEW_LATE = 'late';
    /** Some requested qty has not shipped, and the order could not simply be canceled. */
    const REVIEW_UNSHIPPED = 'unshipped';
    /** The order qualified for a full cancel, but Magento refused or failed it. */
    const REVIEW_CANCEL_FAILED = 'cancel_failed';
    /** More qty requested than the customer holds or the order still has open. */
    const REVIEW_QTY_EXCEEDS = 'qty_exceeds';
    /** An item id that is not a returnable line of this order (unknown, child, virtual or downloadable). */
    const REVIEW_UNKNOWN_ITEM = 'unknown_item';
    /** Nothing left to return or cancel for the requested items. */
    const REVIEW_NOTHING_TO_DO = 'nothing_to_do';
    /** Creating the RMA failed; the reason is logged. */
    const REVIEW_RMA_FAILED = 'rma_failed';

    /**
     * @param RMAInterface|null $rma The withdrawal RMA, new or (when $duplicate) the existing one
     * @param bool $orderCanceled
     * @param bool $late
     * @param string[] $reviewReasons REVIEW_* codes
     * @param array<int, int> $returnQty Order item id => qty put on the RMA
     * @param array<int, int> $unshippedQty Order item id => requested qty not shipped yet
     * @param bool $duplicate True when this ticket already had a withdrawal RMA
     */
    public function __construct(
        public readonly ?RMAInterface $rma,
        public readonly bool $orderCanceled,
        public readonly bool $late,
        public readonly array $reviewReasons,
        public readonly array $returnQty,
        public readonly array $unshippedQty,
        public readonly bool $duplicate = false
    ) {
    }

    /**
     * @return bool
     */
    public function needsReview(): bool
    {
        return !empty($this->reviewReasons);
    }
}
