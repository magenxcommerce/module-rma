<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Model\RMA;

use Magenx\Rma\Api\Data\RMAInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Status rules for RMAs opened from an EU withdrawal declaration.
 *
 * A withdrawal is not a request the merchant may turn down, so such an RMA can
 * never be `rejected`: a late or invalid declaration is closed as `resolved`
 * after staff review instead. It can only be canceled by the customer before
 * the goods are on their way back, and the withdrawal flag cannot be removed
 * once set. Ordinary RMAs are not affected.
 */
class WithdrawalStatusGuard
{
    /**
     * Statuses a withdrawal RMA may move to `canceled_by_customer` from.
     */
    const CANCELABLE_FROM = [
        StatusCodes::NEW_REQUEST,
        StatusCodes::NEED_DETAILS,
        StatusCodes::APPROVED,
    ];

    /**
     * @param StatusResolver $statusResolver
     */
    public function __construct(
        protected readonly StatusResolver $statusResolver
    ) {
    }

    /**
     * @param RMAInterface $rma The RMA about to be saved
     * @param RMAInterface|null $stored The row as currently stored; null for a new RMA
     * @return void
     * @throws LocalizedException When the change breaks a withdrawal rule
     */
    public function assertAllowed(RMAInterface $rma, ?RMAInterface $stored): void
    {
        if ($stored !== null && $stored->isWithdrawal() && !$rma->isWithdrawal()) {
            throw new LocalizedException(__('An RMA opened from a withdrawal cannot be turned into an ordinary return.'));
        }

        if (!$rma->isWithdrawal()) {
            return;
        }

        $newStatusId = (int)$rma->getStatusId();
        $oldStatusId = $stored !== null ? (int)$stored->getStatusId() : null;
        if ($newStatusId === $oldStatusId) {
            return;
        }

        $newCode = $this->statusResolver->getCodeById($newStatusId);

        if ($newCode === StatusCodes::REJECTED) {
            throw new LocalizedException(
                __('A withdrawal cannot be rejected. Close it as Resolved after review instead.')
            );
        }

        if ($newCode === StatusCodes::CANCELED_BY_CUSTOMER) {
            $oldCode = $oldStatusId !== null ? $this->statusResolver->getCodeById($oldStatusId) : null;
            if (!in_array($oldCode, self::CANCELABLE_FROM, true)) {
                throw new LocalizedException(
                    __('A withdrawal can only be canceled by the customer before the goods are sent back.')
                );
            }
        }
    }
}
