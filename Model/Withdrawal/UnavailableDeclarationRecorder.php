<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Model\Withdrawal;

use Magenx\Rma\Api\WithdrawalDeclarationRecorderInterface;
use Magenx\Rma\Service\WithdrawalDeclaration;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;

/**
 * Default recorder while no helpdesk module provides one: withdrawals cannot be
 * declared through the API, so the storefront keeps its fallback form.
 */
class UnavailableDeclarationRecorder implements WithdrawalDeclarationRecorderInterface
{
    /**
     * @param int $storeId
     * @return bool
     */
    public function isAvailable(int $storeId): bool
    {
        return false;
    }

    /**
     * @param OrderInterface $order
     * @param string $name
     * @param string $email
     * @param array $items
     * @param string $message
     * @return WithdrawalDeclaration
     * @throws LocalizedException
     */
    public function record(
        OrderInterface $order,
        string $name,
        string $email,
        array $items,
        string $message
    ): WithdrawalDeclaration {
        throw new LocalizedException(__('Withdrawal declarations are not available.'));
    }
}
