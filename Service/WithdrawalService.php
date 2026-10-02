<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Service;

use Magenx\Rma\Api\CommentRepositoryInterface;
use Magenx\Rma\Api\Data\CommentInterface;
use Magenx\Rma\Api\Data\CommentInterfaceFactory;
use Magenx\Rma\Api\Data\RMAInterface;
use Magenx\Rma\Api\ReasonRepositoryInterface;
use Magenx\Rma\Api\ResolutionTypeRepositoryInterface;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\Rma\Helper\ModuleConfig;
use Magenx\Rma\Model\RMA\StatusCodes;
use Magenx\Rma\Model\RMA\WithdrawalCodes;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Turns a recorded EU withdrawal declaration into return logistics.
 *
 * Call it after the declaration itself is stored (the helpdesk ticket): that
 * record and its confirmation email are the legal part, and this service must
 * never be the reason one is lost. So it does not throw for business
 * conditions; whatever it cannot do automatically comes back as a review reason
 * on the result (and as an internal comment on the RMA when there is one).
 *
 * Per requested item the qty is split into:
 * - qty the customer holds (shipped, not refunded, not already on an open RMA)
 *   which goes on one withdrawal RMA with reason `withdrawal` / resolution `refund`;
 * - qty not shipped yet. When nothing of the order has shipped and the
 *   declaration covers everything still open, the order is canceled. Anything
 *   else unshipped is left to staff, because Magento has no per-item cancel.
 */
class WithdrawalService
{
    /**
     * @param ModuleConfig $moduleConfig
     * @param OrderEligibility $orderEligibility
     * @param WithdrawalEligibility $withdrawalEligibility
     * @param RmaSubmitService $rmaSubmitService
     * @param RMARepositoryInterface $rmaRepository
     * @param ReasonRepositoryInterface $reasonRepository
     * @param ResolutionTypeRepositoryInterface $resolutionTypeRepository
     * @param CommentRepositoryInterface $commentRepository
     * @param CommentInterfaceFactory $commentFactory
     * @param OrderManagementInterface $orderManagement
     * @param SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
     * @param LoggerInterface $logger
     */
    public function __construct(
        protected readonly ModuleConfig $moduleConfig,
        protected readonly OrderEligibility $orderEligibility,
        protected readonly WithdrawalEligibility $withdrawalEligibility,
        protected readonly RmaSubmitService $rmaSubmitService,
        protected readonly RMARepositoryInterface $rmaRepository,
        protected readonly ReasonRepositoryInterface $reasonRepository,
        protected readonly ResolutionTypeRepositoryInterface $resolutionTypeRepository,
        protected readonly CommentRepositoryInterface $commentRepository,
        protected readonly CommentInterfaceFactory $commentFactory,
        protected readonly OrderManagementInterface $orderManagement,
        protected readonly SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        protected readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param OrderInterface $order The order the declaration names, already matched to the declarant
     * @param array<int, int> $items Order item id => qty; empty means the whole order
     * @param string $ticketCode Helpdesk ticket holding the declaration; repeat calls with
     *        the same code return the first result instead of creating a second RMA
     * @param string $declaredAt UTC `Y-m-d H:i:s` the declaration arrived (server time)
     * @return WithdrawalResult
     * @throws LocalizedException When called without a ticket code
     */
    public function submit(OrderInterface $order, array $items, string $ticketCode, string $declaredAt): WithdrawalResult
    {
        if (trim($ticketCode) === '') {
            throw new LocalizedException(__('A withdrawal needs the helpdesk ticket that records it.'));
        }

        $existing = $this->findByTicket($ticketCode);
        if ($existing !== null) {
            return new WithdrawalResult($existing, false, false, [], [], [], true);
        }

        if (!$this->moduleConfig->isWithdrawalEnabled((int)$order->getStoreId())) {
            return new WithdrawalResult(null, false, false, [WithdrawalResult::REVIEW_DISABLED], [], []);
        }

        $review = [];
        [$returnQty, $unshippedQty, $openQty] = $this->split($order, $items, $review);

        $late = !empty($returnQty) && $this->withdrawalEligibility->isLate($order, $declaredAt);
        if ($late) {
            $review[] = WithdrawalResult::REVIEW_LATE;
        }

        $orderCanceled = false;
        if (!empty($unshippedQty)) {
            $orderCanceled = $this->cancelIfWhollyUnshipped($order, $unshippedQty, $openQty, $review);
        }

        if (empty($returnQty) && empty($unshippedQty)) {
            $review[] = WithdrawalResult::REVIEW_NOTHING_TO_DO;
        }

        $rma = null;
        if (!empty($returnQty)) {
            $rma = $this->createRma($order, $returnQty, $ticketCode, $declaredAt, $late, $review);
        }

        $review = array_values(array_unique($review));
        if ($rma !== null && !empty($review)) {
            $this->addReviewNote($rma, $review, $unshippedQty);
        }

        return new WithdrawalResult($rma, $orderCanceled, $late, $review, $returnQty, $unshippedQty);
    }

    /**
     * Every returnable line of the order with what a withdrawal could still cover:
     * `qty_held` (shipped, not refunded, not on an open RMA) and `qty_unshipped`
     * (still to ship). Lines with nothing left are included with zeros, so callers
     * can show the whole order. This is what the storefront offers for selection.
     *
     * @param OrderInterface $order
     * @return array<int, array{order_item_id: int, name: string, sku: string, qty_held: int, qty_unshipped: int}>
     */
    public function getWithdrawableItems(OrderInterface $order): array
    {
        $held = [];
        foreach ($this->orderEligibility->getEligibleItems($order) as $eligible) {
            $held[(int)$eligible['order_item_id']] = (int)$eligible['qty_available'];
        }

        $lines = [];
        foreach ($order->getItems() ?? [] as $orderItem) {
            if (!$this->orderEligibility->isReturnableType($orderItem)) {
                continue;
            }
            $id = (int)$orderItem->getItemId();
            $stillPaidFor = (int)$orderItem->getQtyOrdered()
                - (int)$orderItem->getQtyRefunded()
                - (int)$orderItem->getQtyCanceled();
            $lines[$id] = [
                'order_item_id' => $id,
                'name' => (string)$orderItem->getName(),
                'sku' => (string)$orderItem->getSku(),
                'qty_held' => $held[$id] ?? 0,
                'qty_unshipped' => max(0, $stillPaidFor - $this->orderEligibility->getReturnableQty($orderItem)),
            ];
        }

        return $lines;
    }

    /**
     * @param OrderInterface $order
     * @param array<int, int> $items
     * @param string[] $review
     * @return array{0: array<int, int>, 1: array<int, int>, 2: array<int, int>}
     *         [qty for the RMA, requested qty not shipped, open (unshipped) qty per line]
     */
    protected function split(OrderInterface $order, array $items, array &$review): array
    {
        $lines = $this->getWithdrawableItems($order);
        $available = [];
        $openQty = [];
        foreach ($lines as $id => $line) {
            if ($line['qty_held'] > 0) {
                $available[$id] = $line['qty_held'];
            }
            if ($line['qty_unshipped'] > 0) {
                $openQty[$id] = $line['qty_unshipped'];
            }
        }

        if (empty($items)) {
            foreach (array_keys($lines) as $id) {
                $items[$id] = ($available[$id] ?? 0) + ($openQty[$id] ?? 0);
            }
        }

        $returnQty = [];
        $unshippedQty = [];
        foreach ($items as $id => $requested) {
            $id = (int)$id;
            $requested = (int)$requested;
            if ($requested <= 0) {
                continue;
            }
            if (!isset($lines[$id])) {
                $review[] = WithdrawalResult::REVIEW_UNKNOWN_ITEM;
                continue;
            }

            $toReturn = min($requested, $available[$id] ?? 0);
            $toCancel = min($requested - $toReturn, $openQty[$id] ?? 0);

            if ($toReturn > 0) {
                $returnQty[$id] = $toReturn;
            }
            if ($toCancel > 0) {
                $unshippedQty[$id] = $toCancel;
            }
            if ($requested > $toReturn + $toCancel) {
                $review[] = WithdrawalResult::REVIEW_QTY_EXCEEDS;
            }
        }

        return [$returnQty, $unshippedQty, $openQty];
    }

    /**
     * Cancels the order only when nothing of it has shipped and the declaration
     * covers every open unit; a partial cancel is not something Magento offers.
     *
     * @param OrderInterface $order
     * @param array<int, int> $unshippedQty
     * @param array<int, int> $openQty
     * @param string[] $review
     * @return bool
     */
    protected function cancelIfWhollyUnshipped(
        OrderInterface $order,
        array $unshippedQty,
        array $openQty,
        array &$review
    ): bool {
        $coversEverything = $unshippedQty == $openQty;
        $nothingShipped = $this->withdrawalEligibility->getLastShipmentDate($order) === null;
        $cancelable = !method_exists($order, 'canCancel') || $order->canCancel();

        if (!$coversEverything || !$nothingShipped || !$cancelable) {
            $review[] = WithdrawalResult::REVIEW_UNSHIPPED;
            return false;
        }

        try {
            if ($this->orderManagement->cancel((int)$order->getEntityId())) {
                return true;
            }
        } catch (Throwable $e) {
            $this->logger->error('RMA withdrawal: order cancel failed', [
                'order_id' => $order->getEntityId(),
                'error' => $e->getMessage(),
            ]);
        }

        $review[] = WithdrawalResult::REVIEW_CANCEL_FAILED;

        return false;
    }

    /**
     * @param OrderInterface $order
     * @param array<int, int> $returnQty
     * @param string $ticketCode
     * @param string $declaredAt
     * @param bool $late
     * @param string[] $review
     * @return RMAInterface|null
     */
    protected function createRma(
        OrderInterface $order,
        array $returnQty,
        string $ticketCode,
        string $declaredAt,
        bool $late,
        array &$review
    ): ?RMAInterface {
        $storeId = (int)$order->getStoreId();
        $statusCode = !$late && $this->moduleConfig->isWithdrawalAutoApproveEnabled($storeId)
            ? StatusCodes::APPROVED
            : StatusCodes::NEW_REQUEST;

        $selected = [];
        foreach ($returnQty as $id => $qty) {
            $selected[$id] = ['qty_requested' => $qty, 'condition_id' => null];
        }

        try {
            return $this->rmaSubmitService->createRma(
                $order,
                $order->getCustomerId() ? (int)$order->getCustomerId() : null,
                (string)$order->getCustomerEmail(),
                $this->customerName($order),
                $this->lookupId($this->reasonRepository, WithdrawalCodes::REASON),
                $this->lookupId($this->resolutionTypeRepository, WithdrawalCodes::RESOLUTION_REFUND),
                $selected,
                '',
                $statusCode,
                static function (RMAInterface $rma) use ($ticketCode, $declaredAt): void {
                    $rma->setIsWithdrawal(true);
                    $rma->setHelpdeskTicketCode($ticketCode);
                    $rma->setWithdrawalDeclaredAt($declaredAt);
                }
            );
        } catch (Throwable $e) {
            $this->logger->error('RMA withdrawal: RMA creation failed', [
                'order_id' => $order->getEntityId(),
                'ticket' => $ticketCode,
                'error' => $e->getMessage(),
            ]);
            $review[] = WithdrawalResult::REVIEW_RMA_FAILED;

            return null;
        }
    }

    /**
     * @param ReasonRepositoryInterface|ResolutionTypeRepositoryInterface $repository
     * @param string $code
     * @return int
     * @throws LocalizedException When the lookup was deleted or never seeded
     */
    protected function lookupId(object $repository, string $code): int
    {
        $criteria = $this->searchCriteriaBuilderFactory->create()
            ->addFilter('code', $code)
            ->setPageSize(1)
            ->create();

        foreach ($repository->getList($criteria)->getItems() as $entity) {
            return (int)$entity->getEntityId();
        }

        throw new LocalizedException(__('RMA lookup "%1" does not exist.', $code));
    }

    /**
     * @param string $ticketCode
     * @return RMAInterface|null
     */
    protected function findByTicket(string $ticketCode): ?RMAInterface
    {
        $criteria = $this->searchCriteriaBuilderFactory->create()
            ->addFilter(RMAInterface::HELPDESK_TICKET_CODE, $ticketCode)
            ->addFilter(RMAInterface::IS_WITHDRAWAL, 1)
            ->setPageSize(1)
            ->create();

        foreach ($this->rmaRepository->getList($criteria)->getItems() as $rma) {
            return $rma;
        }

        return null;
    }

    /**
     * Staff-only note listing what needs a human, so it is visible on the RMA itself.
     *
     * @param RMAInterface $rma
     * @param string[] $review
     * @param array<int, int> $unshippedQty
     * @return void
     */
    protected function addReviewNote(RMAInterface $rma, array $review, array $unshippedQty): void
    {
        $lines = [(string)__('Withdrawal needs review: %1.', implode(', ', $review))];
        foreach ($unshippedQty as $id => $qty) {
            $lines[] = (string)__('Not shipped yet: order item %1 x %2.', $id, $qty);
        }

        try {
            $comment = $this->commentFactory->create();
            $comment->setRmaId((int)$rma->getEntityId());
            $comment->setAuthorType(CommentInterface::AUTHOR_TYPE_ADMIN);
            $comment->setAuthorName('System');
            $comment->setComment(implode("\n", $lines));
            $comment->setIsVisibleToCustomer(false);
            $this->commentRepository->save($comment);
        } catch (Throwable $e) {
            $this->logger->error('RMA withdrawal: review note failed', [
                'rma_id' => $rma->getEntityId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param OrderInterface $order
     * @return string
     */
    protected function customerName(OrderInterface $order): string
    {
        $name = trim((string)$order->getCustomerFirstname() . ' ' . (string)$order->getCustomerLastname());
        $billing = $order->getBillingAddress();
        if ($name === '' && $billing !== null) {
            $name = trim((string)$billing->getFirstname() . ' ' . (string)$billing->getLastname());
        }

        return $name !== '' ? $name : 'Customer';
    }
}
