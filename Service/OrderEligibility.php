<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 */
declare(strict_types=1);

namespace Magenx\Rma\Service;

use Magenx\Rma\Helper\ModuleConfig;
use Magenx\Rma\Model\ResourceModel\Item\CollectionFactory as RmaItemCollectionFactory;
use Magento\Sales\Api\Data\OrderInterface;

class OrderEligibility
{
    /**
     * @param ModuleConfig $moduleConfig
     * @param RmaItemCollectionFactory $rmaItemCollectionFactory
     */
    public function __construct(
        protected readonly ModuleConfig $moduleConfig,
        protected readonly RmaItemCollectionFactory $rmaItemCollectionFactory
    ) {
    }

    /**
     * @param OrderInterface $order
     * @return bool
     */
    public function isOrderEligible(OrderInterface $order): bool
    {
        $storeId = (int)$order->getStoreId();

        if (!$this->moduleConfig->isEnabled($storeId)) {
            return false;
        }

        $allowedStatuses = $this->moduleConfig->getAllowedOrderStatuses($storeId);
        if (!in_array($order->getStatus(), $allowedStatuses, true)) {
            return false;
        }

        if (!$this->isWithinReturnPeriod($order)) {
            return false;
        }

        return !empty($this->getEligibleItems($order));
    }

    /**
     * @param OrderInterface $order
     * @return array
     */
    public function getEligibleItems(OrderInterface $order): array
    {
        $orderId = (int)$order->getEntityId();
        $alreadyRequested = $this->getAlreadyRequestedQty($orderId);

        $items = [];
        foreach ($order->getItems() as $orderItem) {
            if ($orderItem->getParentItemId()) {
                continue;
            }

            $productType = $orderItem->getProductType();
            if (in_array($productType, ['virtual', 'downloadable'], true)) {
                continue;
            }

            $orderItemId = (int)$orderItem->getItemId();
            $qtyOrdered = (int)$orderItem->getQtyOrdered();
            $qtyAlreadyRequested = $alreadyRequested[$orderItemId] ?? 0;
            $qtyAvailable = $qtyOrdered - $qtyAlreadyRequested;

            if ($qtyAvailable <= 0) {
                continue;
            }

            $items[] = [
                'order_item_id' => $orderItemId,
                'name' => $orderItem->getName(),
                'sku' => $orderItem->getSku(),
                'qty_ordered' => $qtyOrdered,
                'qty_already_requested' => $qtyAlreadyRequested,
                'qty_available' => $qtyAvailable,
            ];
        }

        return $items;
    }

    /**
     * @param OrderInterface $order
     * @return bool
     */
    protected function isWithinReturnPeriod(OrderInterface $order): bool
    {
        $storeId = (int)$order->getStoreId();
        $returnPeriod = $this->moduleConfig->getReturnPeriod($storeId);

        if ($returnPeriod <= 0) {
            return true;
        }

        $orderDate = strtotime($order->getCreatedAt());
        $cutoffDate = strtotime("-{$returnPeriod} days");

        return $orderDate >= $cutoffDate;
    }

    /**
     * @param int $orderId
     * @return array
     */
    public function getAlreadyRequestedQty(int $orderId): array
    {
        $collection = $this->rmaItemCollectionFactory->create();

        $collection->getSelect()->join(
            ['rma' => $collection->getTable('rma_entity')],
            'main_table.rma_id = rma.entity_id',
            []
        )->where('rma.order_id = ?', $orderId);

        $result = [];
        foreach ($collection as $item) {
            $orderItemId = (int)$item->getData('order_item_id');
            $qty = (int)$item->getData('qty_requested');
            $result[$orderItemId] = ($result[$orderItemId] ?? 0) + $qty;
        }

        return $result;
    }
}
