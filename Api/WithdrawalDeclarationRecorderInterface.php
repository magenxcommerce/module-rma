<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Api;

use Magenx\Rma\Service\WithdrawalDeclaration;
use Magenx\Rma\Service\WithdrawalRequest;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Stores an EU withdrawal declaration and confirms it to the consumer.
 *
 * This is the legal half of a withdrawal, so this module does not implement it:
 * the helpdesk module provides it (Magenx_Helpdesk's DeclarationRecorder) through
 * a DI preference. An implementation MUST
 * - persist the declaration as submitted, with the server time it arrived,
 *   throwing when it cannot, so the caller can tell the consumer it did not go
 *   through; and
 * - send the consumer a confirmation on a durable medium (email) with that date
 *   and time. A failed confirmation does not undo a stored declaration: the
 *   implementation flags it for staff to send by hand instead of throwing.
 *
 * The default preference (UnavailableDeclarationRecorder) reports itself
 * unavailable, which switches the withdrawal mutation off.
 */
interface WithdrawalDeclarationRecorderInterface
{
    /**
     * @param int $storeId
     * @return bool
     */
    public function isAvailable(int $storeId): bool;

    /**
     * @param WithdrawalRequest $request The declaration as submitted
     * @param OrderInterface|null $order The order it names, when the order number
     *        matched and the declarant proved it (order email or owning customer);
     *        null otherwise — staff then find the order themselves
     * @return WithdrawalDeclaration
     * @throws LocalizedException
     */
    public function record(WithdrawalRequest $request, ?OrderInterface $order): WithdrawalDeclaration;
}
