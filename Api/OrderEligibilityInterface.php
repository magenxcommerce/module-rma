<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Api;

use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;

/**
 * What of an order may still be returned: the rule the storefront form, the
 * admin's new-return form and the GraphQL mutation all apply.
 *
 * Other modules that open returns (Magenx_AiMcp's create_rma among them) depend
 * on this interface rather than on the service class behind it, so the rule
 * has one owner and its implementation can change without breaking them.
 */
interface OrderEligibilityInterface
{
    /** Outcomes of explain(). Only RESULT_OK means a return may be created. */
    public const RESULT_OK = 'ok';
    public const RESULT_DISABLED = 'disabled';
    public const RESULT_ORDER_STATUS = 'order_status';
    public const RESULT_RETURN_PERIOD = 'return_period';
    public const RESULT_NOT_SHIPPED = 'not_shipped';
    public const RESULT_NO_ITEMS = 'no_items';

    /**
     * Whether a return may be opened against the order at all.
     *
     * @param OrderInterface $order
     * @return bool
     */
    public function isOrderEligible(OrderInterface $order): bool;

    /**
     * Why an order can or cannot be returned, as one of the RESULT_* codes.
     *
     * @param OrderInterface $order
     * @return string
     */
    public function explain(OrderInterface $order): string;

    /**
     * The order lines that can still be returned, and how many of each.
     *
     * One entry per returnable top-level line with anything left to return:
     * order_item_id, name, sku, qty_ordered, qty_already_requested (on open
     * returns) and qty_available (shipped, less refunded and canceled, less
     * qty_already_requested). Virtual and downloadable lines and the children
     * of a configurable or bundle are never listed.
     *
     * @param OrderInterface $order
     * @return array<int, array{order_item_id: int, name: string|null, sku: string|null, qty_ordered: int,
     *     qty_already_requested: int, qty_available: int}>
     */
    public function getEligibleItems(OrderInterface $order): array;

    /**
     * How many of one line can be returned before other returns are counted:
     * shipped, less refunded and canceled.
     *
     * @param OrderItemInterface $orderItem
     * @return int
     */
    public function getReturnableQty(OrderItemInterface $orderItem): int;
}
