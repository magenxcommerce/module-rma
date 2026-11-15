<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: save() checks the item belongs to the RMA and its order, and
 * that a new item's qty is returnable.
 */
declare(strict_types=1);

namespace Magenx\Rma\Model;

use Magenx\Rma\Api\Data\ItemInterface;
use Magenx\Rma\Api\Data\ItemSearchResultsInterface;
use Magenx\Rma\Api\ItemRepositoryInterface;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\Rma\Api\RmaItemManagementInterface;
use Magento\Framework\Api\SearchCriteriaInterface;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Exception\CouldNotDeleteException;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magenx\Rma\Service\OrderEligibility;

class RmaItemManagement extends AbstractRmaManagement implements RmaItemManagementInterface
{
    /**
     * @param RMARepositoryInterface $rmaRepository
     * @param SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
     * @param SortOrderBuilder $sortOrderBuilder
     * @param ItemRepositoryInterface $itemRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param OrderEligibility $orderEligibility
     */
    public function __construct(
        RMARepositoryInterface $rmaRepository,
        SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory,
        SortOrderBuilder $sortOrderBuilder,
        protected readonly ItemRepositoryInterface $itemRepository,
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly OrderEligibility $orderEligibility
    ) {
        parent::__construct($rmaRepository, $searchCriteriaBuilderFactory, $sortOrderBuilder);
    }

    /**
     * @param int $rmaId
     * @param SearchCriteriaInterface $searchCriteria
     * @return ItemSearchResultsInterface
     * @throws NoSuchEntityException
     */
    public function getList(int $rmaId, SearchCriteriaInterface $searchCriteria): ItemSearchResultsInterface
    {
        $this->validateRmaExists($rmaId);

        return $this->itemRepository->getList(
            $this->buildScopedSearchCriteria($rmaId, $searchCriteria)
        );
    }

    /**
     * @param int $rmaId
     * @param ItemInterface $item
     * @return ItemInterface
     * @throws NoSuchEntityException
     * @throws CouldNotSaveException
     * @throws LocalizedException
     */
    public function save(int $rmaId, ItemInterface $item): ItemInterface
    {
        $rma = $this->rmaRepository->get($rmaId);

        if ($item->getEntityId()) {
            // An update may not move an item to another RMA or another order line.
            $existing = $this->itemRepository->get((int)$item->getEntityId());
            if ($existing->getRmaId() !== $rmaId) {
                throw new LocalizedException(
                    __('Item %1 does not belong to RMA %2.', $item->getEntityId(), $rmaId)
                );
            }
            if ($item->getOrderItemId() && $item->getOrderItemId() !== $existing->getOrderItemId()) {
                throw new LocalizedException(__('The order item of a return item cannot be changed.'));
            }
        } else {
            // A new item follows the same rules as a return created anywhere else (PRD BR-1, BR-2).
            $order = $this->orderRepository->get($rma->getOrderId());
            $available = array_column(
                $this->orderEligibility->getEligibleItems($order),
                'qty_available',
                'order_item_id'
            );
            $qty = $item->getQtyRequested();
            if ($qty < 1 || $qty > ($available[$item->getOrderItemId()] ?? 0)) {
                throw new LocalizedException(
                    __('Item %1 is not eligible for return in this quantity.', $item->getOrderItemId())
                );
            }
        }

        $item->setRmaId($rmaId);

        return $this->itemRepository->save($item);
    }

    /**
     * @param int $rmaId
     * @param int $itemId
     * @return bool
     * @throws NoSuchEntityException
     * @throws CouldNotDeleteException
     * @throws LocalizedException
     */
    public function deleteById(int $rmaId, int $itemId): bool
    {
        $this->validateRmaExists($rmaId);

        $item = $this->itemRepository->get($itemId);
        if ($item->getRmaId() !== $rmaId) {
            throw new LocalizedException(__('Item does not belong to this RMA.'));
        }

        return $this->itemRepository->delete($item);
    }
}
