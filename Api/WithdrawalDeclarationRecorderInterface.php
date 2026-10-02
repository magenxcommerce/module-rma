<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Api;

use Magenx\Rma\Service\WithdrawalDeclaration;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Stores an EU withdrawal declaration and confirms it to the consumer.
 *
 * This is the legal half of a withdrawal, so this module does not implement it:
 * the helpdesk module provides it (a ticket labelled as a withdrawal) through a
 * DI preference (Magenx_Helpdesk's DeclarationRecorder). An implementation MUST
 * - persist the declaration with the server time it arrived, throwing when it
 *   cannot, so the caller can tell the consumer it did not go through; and
 * - send the consumer a confirmation on a durable medium (email) with the
 *   declaration's content and that date and time. A failed confirmation does not
 *   undo a stored declaration: the implementation flags it for staff to send by
 *   hand instead of throwing.
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
     * @param OrderInterface $order
     * @param string $name Declarant's name as entered
     * @param string $email Declarant's email as entered
     * @param array<int, array{name: string, sku: string, qty: int}> $items Lines withdrawn from;
     *        empty means the whole order
     * @param string $message Optional free text from the declarant
     * @return WithdrawalDeclaration
     * @throws LocalizedException
     */
    public function record(
        OrderInterface $order,
        string $name,
        string $email,
        array $items,
        string $message
    ): WithdrawalDeclaration;
}
