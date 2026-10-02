<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Service;

use Magenx\Rma\Api\WithdrawalDeclarationRecorderInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One server-side flow for a withdrawal: record the declaration first (the
 * legal act, with its confirmation email), then hand it to WithdrawalService
 * for the RMA or the order cancel.
 *
 * Only the recording step can fail the call. Once the declaration is stored,
 * the consumer has withdrawn, so a failure in the return step is logged and
 * left to staff instead of being reported as a failed declaration.
 */
class WithdrawalSubmitService
{
    /**
     * @param WithdrawalDeclarationRecorderInterface $recorder
     * @param WithdrawalService $withdrawalService
     * @param LoggerInterface $logger
     */
    public function __construct(
        protected readonly WithdrawalDeclarationRecorderInterface $recorder,
        protected readonly WithdrawalService $withdrawalService,
        protected readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param int $storeId
     * @return bool
     */
    public function isAvailable(int $storeId): bool
    {
        return $this->recorder->isAvailable($storeId);
    }

    /**
     * @param OrderInterface $order Already matched to the declarant by the caller
     * @param string $name
     * @param string $email
     * @param array<int, int> $items Order item id => qty; empty means the whole order
     * @param string $message
     * @return WithdrawalSubmission
     * @throws LocalizedException When the declaration could not be recorded
     */
    public function submit(
        OrderInterface $order,
        string $name,
        string $email,
        array $items,
        string $message = ''
    ): WithdrawalSubmission {
        if (!$this->recorder->isAvailable((int)$order->getStoreId())) {
            throw new LocalizedException(__('Withdrawal declarations are not available.'));
        }

        $declaration = $this->recorder->record(
            $order,
            $name,
            $email,
            $this->describeItems($order, $items),
            $message
        );

        try {
            $result = $this->withdrawalService->submit(
                $order,
                $items,
                $declaration->ticketCode,
                $declaration->declaredAt
            );
        } catch (Throwable $e) {
            $this->logger->critical('RMA withdrawal: return step failed after the declaration was recorded', [
                'order_id' => $order->getEntityId(),
                'ticket' => $declaration->ticketCode,
                'error' => $e->getMessage(),
            ]);
            $result = null;
        }

        return new WithdrawalSubmission($declaration, $result);
    }

    /**
     * Item lines for the declaration record, as the declarant chose them. Ids that
     * are not lines of the order are kept (by id) so the record shows exactly what
     * was declared.
     *
     * @param OrderInterface $order
     * @param array<int, int> $items
     * @return array<int, array{name: string, sku: string, qty: int}>
     */
    protected function describeItems(OrderInterface $order, array $items): array
    {
        if (empty($items)) {
            return [];
        }

        $lines = $this->withdrawalService->getWithdrawableItems($order);
        $described = [];
        foreach ($items as $id => $qty) {
            $id = (int)$id;
            $described[$id] = [
                'name' => $lines[$id]['name'] ?? (string)__('Order item %1', $id),
                'sku' => $lines[$id]['sku'] ?? '',
                'qty' => (int)$qty,
            ];
        }

        return $described;
    }
}
