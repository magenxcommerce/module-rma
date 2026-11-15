<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: createRma() takes an initial status and a callback that
 * fills extra RMA fields before the save; locks the order row so parallel returns
 * cannot together exceed the returnable qty; refuses item quantities below 1 and
 * unknown or inactive reason, resolution and condition ids, and the withdrawal
 * reason on an ordinary return.
 */
declare(strict_types=1);

namespace Magenx\Rma\Service;

use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magenx\Rma\Api\Data\RMAInterface;
use Magenx\Rma\Api\Data\RMAInterfaceFactory;
use Magenx\Rma\Api\ItemConditionRepositoryInterface;
use Magenx\Rma\Api\ItemRepositoryInterface;
use Magenx\Rma\Api\ReasonRepositoryInterface;
use Magenx\Rma\Api\ResolutionTypeRepositoryInterface;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\Rma\Helper\ModuleConfig;
use Magenx\Rma\Model\ItemFactory;
use Magenx\Rma\Model\RMA\StatusCodes;
use Magenx\Rma\Model\RMA\StatusResolver;
use Magenx\Rma\Model\RMA\WithdrawalCodes;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\Data\OrderInterface;
use Throwable;

class RmaSubmitService
{
    /**
     * @param RMARepositoryInterface $rmaRepository
     * @param RMAInterfaceFactory $rmaFactory
     * @param ItemFactory $itemFactory
     * @param ItemRepositoryInterface $itemRepository
     * @param StatusResolver $statusResolver
     * @param ModuleConfig $moduleConfig
     * @param AttachmentService $attachmentService
     * @param OrderEligibility $orderEligibility
     * @param ResourceConnection $resourceConnection
     * @param EventManagerInterface $eventManager
     * @param ReasonRepositoryInterface $reasonRepository
     * @param ResolutionTypeRepositoryInterface $resolutionTypeRepository
     * @param ItemConditionRepositoryInterface $itemConditionRepository
     */
    public function __construct(
        protected readonly RMARepositoryInterface $rmaRepository,
        protected readonly RMAInterfaceFactory $rmaFactory,
        protected readonly ItemFactory $itemFactory,
        protected readonly ItemRepositoryInterface $itemRepository,
        protected readonly StatusResolver $statusResolver,
        protected readonly ModuleConfig $moduleConfig,
        protected readonly AttachmentService $attachmentService,
        protected readonly OrderEligibility $orderEligibility,
        protected readonly ResourceConnection $resourceConnection,
        protected readonly EventManagerInterface $eventManager,
        protected readonly ReasonRepositoryInterface $reasonRepository,
        protected readonly ResolutionTypeRepositoryInterface $resolutionTypeRepository,
        protected readonly ItemConditionRepositoryInterface $itemConditionRepository,
    ) {
    }

    /**
     * @param array $itemsData
     * @return array
     */
    public function getSelectedItems(array $itemsData): array
    {
        $selected = [];

        foreach ($itemsData as $orderItemId => $itemData) {
            if (empty($itemData['selected'])) {
                continue;
            }

            $qtyRequested = (int)($itemData['qty_requested'] ?? 0);
            if ($qtyRequested <= 0) {
                continue;
            }

            $selected[(int)$orderItemId] = [
                'qty_requested' => $qtyRequested,
                'condition_id' => !empty($itemData['condition_id']) ? (int)$itemData['condition_id'] : null,
            ];
        }

        return $selected;
    }

    /**
     * @param OrderInterface $order
     * @param int|null $customerId
     * @param string $customerEmail
     * @param string $customerName
     * @param int $reasonId
     * @param int $resolutionTypeId
     * @param array $selectedItems
     * @param string $attachmentsJson
     * @param string|null $initialStatusCode Overrides the auto-approve setting when given
     * @param callable|null $prepare Receives the unsaved RMA, to set fields such as the
     *        withdrawal data inside the same transaction and before rma_commit_after fires
     * @return RMAInterface
     * @throws CouldNotSaveException
     * @throws LocalizedException
     * @throws Throwable
     */
    public function createRma(
        OrderInterface $order,
        ?int $customerId,
        string $customerEmail,
        string $customerName,
        int $reasonId,
        int $resolutionTypeId,
        array $selectedItems,
        string $attachmentsJson = '',
        ?string $initialStatusCode = null,
        ?callable $prepare = null
    ): RMAInterface {
        if (!$reasonId || !$resolutionTypeId) {
            throw new LocalizedException(__('Invalid request.'));
        }

        $storeId = (int)$order->getStoreId();

        $statusCode = $initialStatusCode ?? ($this->moduleConfig->isAutoApproveEnabled($storeId)
            ? StatusCodes::APPROVED
            : StatusCodes::NEW_REQUEST);
        $statusId = $this->statusResolver->getIdByCode($statusCode);

        if (!$statusId) {
            throw new LocalizedException(__('Could not determine the initial RMA status.'));
        }

        $rma = $this->rmaFactory->create();
        $rma->setOrderId((int)$order->getEntityId());
        $rma->setCustomerId($customerId);
        $rma->setStoreId($storeId);
        $rma->setCustomerEmail($customerEmail);
        $rma->setCustomerName($customerName);
        $rma->setStatusId($statusId);
        $rma->setReasonId($reasonId);
        $rma->setResolutionTypeId($resolutionTypeId);

        if ($prepare !== null) {
            $prepare($rma);
        }

        $this->assertLookupsSelectable($rma->isWithdrawal(), $reasonId, $resolutionTypeId, $selectedItems);

        $connection = $this->resourceConnection->getConnection();
        $connection->beginTransaction();

        try {
            $this->lockOrder((int)$order->getEntityId());
            $this->rmaRepository->save($rma);
            $rmaId = (int)$rma->getEntityId();
            $this->saveItems($rmaId, $selectedItems, $order);
            $this->attachmentService->saveFromJson($attachmentsJson, $rmaId, null, $storeId);
            $connection->commit();
            $this->eventManager->dispatch('rma_commit_after', ['rma' => $rma]);
        } catch (Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $rma;
    }

    /**
     * Refuses unknown or inactive reason, resolution and condition ids before anything
     * is written, so a bad id is a clear input error instead of a foreign-key failure.
     * A withdrawal return gets its reason and resolution from the system, so those two
     * may be inactive in the customer choice lists.
     *
     * @param bool $systemSet True for a withdrawal return
     * @param int $reasonId
     * @param int $resolutionTypeId
     * @param array $selectedItems
     * @return void
     * @throws LocalizedException
     */
    protected function assertLookupsSelectable(
        bool $systemSet,
        int $reasonId,
        int $resolutionTypeId,
        array $selectedItems
    ): void {

        $reason = $this->assertSelectable(
            $this->reasonRepository,
            $reasonId,
            $systemSet,
            __('The selected return reason is not available.')
        );
        // The withdrawal reason is set by the system for withdrawal returns only.
        if (!$systemSet && $reason->getCode() === WithdrawalCodes::REASON) {
            throw new LocalizedException(__('The selected return reason is not available.'));
        }
        $this->assertSelectable(
            $this->resolutionTypeRepository,
            $resolutionTypeId,
            $systemSet,
            __('The selected resolution is not available.')
        );

        foreach ($selectedItems as $itemData) {
            if (!empty($itemData['condition_id'])) {
                $this->assertSelectable(
                    $this->itemConditionRepository,
                    (int)$itemData['condition_id'],
                    false,
                    __('The selected item condition is not available.')
                );
            }
        }
    }

    /**
     * @param ReasonRepositoryInterface|ResolutionTypeRepositoryInterface|ItemConditionRepositoryInterface $repository
     * @param int $entityId
     * @param bool $allowInactive
     * @param \Magento\Framework\Phrase $refusal
     * @return object The loaded lookup entry
     * @throws LocalizedException
     */
    protected function assertSelectable(
        object $repository,
        int $entityId,
        bool $allowInactive,
        \Magento\Framework\Phrase $refusal
    ): object {
        try {
            $entity = $repository->get($entityId);
        } catch (NoSuchEntityException) {
            throw new LocalizedException($refusal);
        }

        if (!$allowInactive && !$entity->getIsActive()) {
            throw new LocalizedException($refusal);
        }

        return $entity;
    }

    /**
     * Serializes return creation per order.
     *
     * The returnable-qty check in saveItems() is a plain read. Without a lock, two
     * parallel requests for the same order both pass it and together hold more units
     * than the customer has. Locking the order row first makes the second request wait;
     * its first consistent read then comes after the lock and sees the committed rows.
     * Must run as the first statement of the transaction.
     *
     * @param int $orderId
     * @return void
     */
    protected function lockOrder(int $orderId): void
    {
        $connection = $this->resourceConnection->getConnection();
        $connection->fetchOne(
            'SELECT entity_id FROM ' . $connection->quoteIdentifier($this->resourceConnection->getTableName('sales_order'))
            . ' WHERE entity_id = ? FOR UPDATE',
            [$orderId]
        );
    }

    /**
     * @param int $rmaId
     * @param array $selectedItems
     * @param OrderInterface $order
     * @return void
     * @throws CouldNotSaveException
     * @throws LocalizedException
     */
    public function saveItems(int $rmaId, array $selectedItems, OrderInterface $order): void
    {
        $eligibleItems = [];
        foreach ($this->orderEligibility->getEligibleItems($order) as $eligibleItem) {
            $eligibleItems[$eligibleItem['order_item_id']] = $eligibleItem['qty_available'];
        }

        foreach ($selectedItems as $orderItemId => $itemData) {
            if (!isset($eligibleItems[$orderItemId])) {
                throw new LocalizedException(
                    __('Item ID %1 is not eligible for return on order #%2.', $orderItemId, $order->getIncrementId())
                );
            }

            if ((int)$itemData['qty_requested'] < 1) {
                throw new LocalizedException(
                    __('Each item needs an order item ID and a quantity above zero.')
                );
            }

            if ($itemData['qty_requested'] > $eligibleItems[$orderItemId]) {
                throw new LocalizedException(
                    __('Requested qty for item %1 exceeds available qty (%2).', $orderItemId, $eligibleItems[$orderItemId])
                );
            }

            $item = $this->itemFactory->create();
            $item->setRmaId($rmaId);
            $item->setOrderItemId($orderItemId);
            $item->setQtyRequested($itemData['qty_requested']);
            $item->setConditionId($itemData['condition_id']);
            $this->itemRepository->save($item);
        }
    }
}
