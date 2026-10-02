<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Service;

use DateTimeImmutable;
use DateTimeZone;
use Magenx\Rma\Helper\ModuleConfig;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Throwable;

/**
 * Timing rules for an EU consumer withdrawal, kept apart from OrderEligibility
 * because they differ from an ordinary return: the period runs from receipt of
 * the goods (approximated by the last shipment plus transit days), not from the
 * order date, and the order status does not matter.
 *
 * Nothing here refuses a declaration. A late one is still a declaration the
 * merchant must answer, so callers record it and flag it for staff review.
 */
class WithdrawalEligibility
{
    /**
     * @param ModuleConfig $moduleConfig
     * @param ShipmentRepositoryInterface $shipmentRepository
     * @param SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
     */
    public function __construct(
        protected readonly ModuleConfig $moduleConfig,
        protected readonly ShipmentRepositoryInterface $shipmentRepository,
        protected readonly SearchCriteriaBuilderFactory $searchCriteriaBuilderFactory
    ) {
    }

    /**
     * Created-at (UTC, `Y-m-d H:i:s`) of the order's most recent shipment, or
     * null when nothing has shipped. The period for an order delivered in
     * several parcels starts with the last one.
     *
     * @param OrderInterface $order
     * @return string|null
     */
    public function getLastShipmentDate(OrderInterface $order): ?string
    {
        $criteria = $this->searchCriteriaBuilderFactory->create()
            ->addFilter('order_id', (int)$order->getEntityId())
            ->create();

        $latest = null;
        foreach ($this->shipmentRepository->getList($criteria)->getItems() as $shipment) {
            $createdAt = (string)$shipment->getCreatedAt();
            if ($createdAt !== '' && ($latest === null || $createdAt > $latest)) {
                $latest = $createdAt;
            }
        }

        return $latest;
    }

    /**
     * Last moment (UTC) a declaration is on time: the end of the day that is
     * `period_days` after the assumed receipt date. Null while nothing has
     * shipped, because the period has not started.
     *
     * @param OrderInterface $order
     * @return DateTimeImmutable|null
     */
    public function getDeadline(OrderInterface $order): ?DateTimeImmutable
    {
        $shippedAt = $this->getLastShipmentDate($order);
        if ($shippedAt === null) {
            return null;
        }

        $storeId = (int)$order->getStoreId();
        $days = $this->moduleConfig->getWithdrawalTransitDays($storeId)
            + $this->moduleConfig->getWithdrawalPeriodDays($storeId);

        try {
            $shipped = new DateTimeImmutable($shippedAt, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return null;
        }

        return $shipped->modify('+' . max(0, $days) . ' days')->setTime(23, 59, 59);
    }

    /**
     * @param OrderInterface $order
     * @param string $declaredAt UTC `Y-m-d H:i:s`, the moment the declaration arrived
     * @return bool
     */
    public function isLate(OrderInterface $order, string $declaredAt): bool
    {
        $deadline = $this->getDeadline($order);
        if ($deadline === null) {
            return false;
        }

        try {
            $declared = new DateTimeImmutable($declaredAt, new DateTimeZone('UTC'));
        } catch (Throwable) {
            return false;
        }

        return $declared > $deadline;
    }
}
