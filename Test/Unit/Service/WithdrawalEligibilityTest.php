<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Service;

use Magenx\Rma\Helper\ModuleConfig;
use Magenx\Rma\Service\WithdrawalEligibility;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\Data\ShipmentSearchResultInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WithdrawalEligibilityTest extends TestCase
{
    private ModuleConfig&MockObject $moduleConfig;
    private ShipmentRepositoryInterface&MockObject $shipmentRepository;
    private WithdrawalEligibility $eligibility;

    protected function setUp(): void
    {
        $this->moduleConfig = $this->createMock(ModuleConfig::class);
        $this->moduleConfig->method('getWithdrawalPeriodDays')->willReturn(14);
        $this->shipmentRepository = $this->createMock(ShipmentRepositoryInterface::class);

        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('create')->willReturn($this->createMock(SearchCriteria::class));
        $builderFactory = $this->createMock(SearchCriteriaBuilderFactory::class);
        $builderFactory->method('create')->willReturn($builder);

        $this->eligibility = new WithdrawalEligibility(
            $this->moduleConfig,
            $this->shipmentRepository,
            $builderFactory
        );
    }

    private function withShipments(string ...$createdAt): void
    {
        $shipments = array_map(
            fn(string $date) => $this->createConfiguredMock(ShipmentInterface::class, ['getCreatedAt' => $date]),
            $createdAt
        );
        $result = $this->createMock(ShipmentSearchResultInterface::class);
        $result->method('getItems')->willReturn($shipments);
        $this->shipmentRepository->method('getList')->willReturn($result);
    }

    private function order(): OrderInterface
    {
        return $this->createConfiguredMock(OrderInterface::class, ['getEntityId' => 5, 'getStoreId' => 1]);
    }

    public function testLastShipmentDateIsTheLatestShipment(): void
    {
        $this->withShipments('2026-09-01 10:00:00', '2026-09-05 08:00:00', '2026-09-03 12:00:00');

        $this->assertSame('2026-09-05 08:00:00', $this->eligibility->getLastShipmentDate($this->order()));
    }

    public function testNoShipmentMeansNoDeadlineAndNeverLate(): void
    {
        $this->withShipments();

        $this->assertNull($this->eligibility->getLastShipmentDate($this->order()));
        $this->assertNull($this->eligibility->getDeadline($this->order()));
        $this->assertFalse($this->eligibility->isLate($this->order(), '2030-01-01 00:00:00'));
    }

    public function testDeadlineIsEndOfDayPeriodAfterShipmentPlusTransit(): void
    {
        $this->moduleConfig->method('getWithdrawalTransitDays')->willReturn(2);
        $this->withShipments('2026-09-01 10:00:00');

        $this->assertSame(
            '2026-09-17 23:59:59',
            $this->eligibility->getDeadline($this->order())->format('Y-m-d H:i:s')
        );
    }

    public function testDeclarationOnTheLastDayIsOnTime(): void
    {
        $this->withShipments('2026-09-01 10:00:00');

        $this->assertFalse($this->eligibility->isLate($this->order(), '2026-09-15 23:59:59'));
    }

    public function testDeclarationAfterTheLastDayIsLate(): void
    {
        $this->withShipments('2026-09-01 10:00:00');

        $this->assertTrue($this->eligibility->isLate($this->order(), '2026-09-16 00:00:00'));
    }

    public function testOrderStatusAndOrderDateDoNotMatter(): void
    {
        // Ordered long ago, shipped recently: still on time.
        $this->withShipments('2026-09-20 10:00:00');
        $order = $this->createConfiguredMock(OrderInterface::class, [
            'getEntityId' => 5,
            'getStoreId' => 1,
            'getCreatedAt' => '2026-01-01 00:00:00',
            'getStatus' => 'processing',
        ]);

        $this->assertFalse($this->eligibility->isLate($order, '2026-09-25 12:00:00'));
    }
}
