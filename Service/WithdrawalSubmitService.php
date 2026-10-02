<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Service;

use Magenx\Rma\Api\WithdrawalDeclarationRecorderInterface;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records a withdrawal declaration from the storefront form.
 *
 * The declaration is taken as submitted — free text, no order lookup on the
 * consumer's side — and stored through the recorder. When the order number
 * happens to match an order the declarant can prove (order email, or the
 * logged-in customer who placed it), the record is linked to that order so staff
 * start from it; nothing else is decided here. Opening the withdrawal return or
 * canceling the order (WithdrawalService) is staff work after review.
 */
class WithdrawalSubmitService
{
    /**
     * @param WithdrawalDeclarationRecorderInterface $recorder
     * @param OrderRepositoryInterface $orderRepository
     * @param SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        protected readonly WithdrawalDeclarationRecorderInterface $recorder,
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
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
     * @param WithdrawalRequest $request
     * @return WithdrawalDeclaration
     * @throws LocalizedException When the declaration could not be recorded
     */
    public function submit(WithdrawalRequest $request): WithdrawalDeclaration
    {
        if (!$this->recorder->isAvailable($request->storeId)) {
            throw new LocalizedException(__('Withdrawal declarations are not available.'));
        }

        return $this->recorder->record($request, $this->matchOrder($request));
    }

    /**
     * The order the declaration names, when the declarant can prove it: the
     * order number matches an order of this store AND either the email equals the
     * order's email (case-insensitive) or the logged-in customer placed it.
     * Anything else is null — never an error: a mistyped number is still a valid
     * declaration.
     *
     * @param WithdrawalRequest $request
     * @return OrderInterface|null
     */
    public function matchOrder(WithdrawalRequest $request): ?OrderInterface
    {
        $orderNumber = trim($request->orderNumber);
        if ($orderNumber === '') {
            return null;
        }

        try {
            $criteria = $this->searchCriteriaBuilderFactory->create()
                ->addFilter('increment_id', $orderNumber)
                ->addFilter('store_id', $request->storeId)
                ->setPageSize(1)
                ->create();
            $orders = $this->orderRepository->getList($criteria)->getItems();
        } catch (Throwable $e) {
            $this->logger->warning('RMA withdrawal: order match failed', ['error' => $e->getMessage()]);
            return null;
        }

        $order = reset($orders);
        if (!$order) {
            return null;
        }

        $email = strtolower(trim($request->email));
        $emailMatches = $email !== ''
            && hash_equals(strtolower((string)$order->getCustomerEmail()), $email);
        $isOwner = $request->customerId !== null
            && (int)$order->getCustomerId() === $request->customerId
            && $request->customerId > 0;

        return $emailMatches || $isOwner ? $order : null;
    }
}
