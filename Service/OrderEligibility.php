<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: returnable qty is based on shipped, refunded and canceled
 * qty; rejected, canceled and refunded RMAs no longer hold qty; added explain().
 */
declare(strict_types=1);

namespace Magenx\Rma\Service;

use Magenx\Rma\Helper\ModuleConfig;
use Magenx\Rma\Model\RMA\StatusCodes;
use Magenx\Rma\Model\RMA\StatusResolver;
use Magenx\Rma\Model\ResourceModel\Item\CollectionFactory as RmaItemCollectionFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

class OrderEligibility
{
    /**
     * Outcomes of explain(). Only RESULT_OK means a return may be created.
     */
    const RESULT_OK = 'ok';
    const RESULT_DISABLED = 'disabled';
    const RESULT_ORDER_STATUS = 'order_status';
    const RESULT_RETURN_PERIOD = 'return_period';
    const RESULT_NOT_SHIPPED = 'not_shipped';
    const RESULT_NO_ITEMS = 'no_items';

    /**
     * RMA statuses whose items no longer hold order qty. A rejected or canceled
     * request returned nothing. A refunded one is already counted in the order
     * item's qty_refunded, so counting it again would subtract the qty twice.
     */
    const RELEASED_STATUS_CODES = [
        StatusCodes::REJECTED,
        StatusCodes::CANCELED_BY_CUSTOMER,
        StatusCodes::REFUNDED,
    ];

    /**
     * @param ModuleConfig $moduleConfig
     * @param RmaItemCollectionFactory $rmaItemCollectionFactory
     * @param StatusResolver $statusResolver
     */
    public function __construct(
        protected readonly ModuleConfig $moduleConfig,
        protected readonly RmaItemCollectionFactory $rmaItemCollectionFactory,
        protected readonly StatusResolver $statusResolver
    ) {
    }

    /**
     * @param OrderInterface $order
     * @return bool
     */
    public function isOrderEligible(OrderInterface $order): bool
    {
        return $this->explain($order) === self::RESULT_OK;
    }

    /**
     * Why an order can or cannot be returned, as one of the RESULT_* codes, so
     * a caller can tell "too late" from "not shipped yet" from "nothing left".
     *
     * @param OrderInterface $order
     * @return string
     */
    public function explain(OrderInterface $order): string
    {
        $storeId = (int)$order->getStoreId();

        if (!$this->moduleConfig->isEnabled($storeId)) {
            return self::RESULT_DISABLED;
        }

        $allowedStatuses = $this->moduleConfig->getAllowedOrderStatuses($storeId);
        if (!in_array($order->getStatus(), $allowedStatuses, true)) {
            return self::RESULT_ORDER_STATUS;
        }

        if (!$this->isWithinReturnPeriod($order)) {
            return self::RESULT_RETURN_PERIOD;
        }

        if (!empty($this->getEligibleItems($order))) {
            return self::RESULT_OK;
        }

        return $this->hasShippedItems($order) ? self::RESULT_NO_ITEMS : self::RESULT_NOT_SHIPPED;
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
            if (!$this->isReturnableType($orderItem)) {
                continue;
            }

            $orderItemId = (int)$orderItem->getItemId();
            $qtyOrdered = (int)$orderItem->getQtyOrdered();
            $qtyAlreadyRequested = $alreadyRequested[$orderItemId] ?? 0;
            $qtyAvailable = $this->getReturnableQty($orderItem) - $qtyAlreadyRequested;

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
     * Qty of an order item the customer actually holds: what was shipped, capped
     * by what is still paid for (ordered minus refunded and canceled).
     *
     * The cap, not a plain subtraction, keeps a refund of never-shipped qty
     * (ordered 3, refunded 1 before dispatch, shipped 2) from also reducing the
     * shipped qty.
     *
     * @param OrderItemInterface $orderItem
     * @return int
     */
    public function getReturnableQty(OrderItemInterface $orderItem): int
    {
        $stillPaidFor = (int)$orderItem->getQtyOrdered()
            - (int)$orderItem->getQtyRefunded()
            - (int)$orderItem->getQtyCanceled();

        return max(0, min($this->getShippedQty($orderItem), $stillPaidFor));
    }

    /**
     * A bundle shipped separately records shipments on its children only. Its
     * shipped qty is then the number of complete bundles the children add up to.
     *
     * @param OrderItemInterface $orderItem
     * @return int
     */
    protected function getShippedQty(OrderItemInterface $orderItem): int
    {
        $qtyShipped = (int)$orderItem->getQtyShipped();
        $qtyOrdered = (float)$orderItem->getQtyOrdered();

        if ($qtyShipped > 0 || $qtyOrdered <= 0 || !method_exists($orderItem, 'getChildrenItems')) {
            return $qtyShipped;
        }

        $children = $orderItem->getChildrenItems();
        if (empty($children)) {
            return 0;
        }

        $bundles = null;
        foreach ($children as $child) {
            $perBundle = (float)$child->getQtyOrdered() / $qtyOrdered;
            if ($perBundle <= 0) {
                continue;
            }
            $complete = (int)floor((float)$child->getQtyShipped() / $perBundle);
            $bundles = $bundles === null ? $complete : min($bundles, $complete);
        }

        return $bundles ?? 0;
    }

    /**
     * @param OrderInterface $order
     * @return bool
     */
    protected function hasShippedItems(OrderInterface $order): bool
    {
        foreach ($order->getItems() ?? [] as $orderItem) {
            if ($this->isReturnableType($orderItem) && $this->getShippedQty($orderItem) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Top-level, physical items only: children are returned through their
     * parent, and virtual or downloadable products are never shipped.
     *
     * @param OrderItemInterface $orderItem
     * @return bool
     */
    public function isReturnableType(OrderItemInterface $orderItem): bool
    {
        if ($orderItem->getParentItemId()) {
            return false;
        }

        return !in_array($orderItem->getProductType(), ['virtual', 'downloadable'], true);
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
     * Qty per order item already held by this order's RMAs, leaving out the
     * RMAs in RELEASED_STATUS_CODES.
     *
     * @param int $orderId
     * @return array
     */
    public function getAlreadyRequestedQty(int $orderId): array
    {
        $collection = $this->rmaItemCollectionFactory->create();

        $select = $collection->getSelect()->join(
            ['rma' => $collection->getTable('rma_entity')],
            'main_table.rma_id = rma.entity_id',
            []
        )->where('rma.order_id = ?', $orderId);

        $releasedStatusIds = $this->statusResolver->getIdsByCodes(self::RELEASED_STATUS_CODES);
        if (!empty($releasedStatusIds)) {
            $select->where('rma.status_id NOT IN (?)', $releasedStatusIds);
        }

        $result = [];
        foreach ($collection as $item) {
            $orderItemId = (int)$item->getData('order_item_id');
            $qty = (int)$item->getData('qty_requested');
            $result[$orderItemId] = ($result[$orderItemId] ?? 0) + $qty;
        }

        return $result;
    }
}
