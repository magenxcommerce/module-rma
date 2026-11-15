<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Model;

use Magenx\Rma\Api\Data\ItemInterface;
use Magenx\Rma\Api\Data\RMAInterface;
use Magenx\Rma\Api\ItemRepositoryInterface;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\Rma\Model\RmaItemManagement;
use Magenx\Rma\Service\OrderEligibility;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Api\SortOrderBuilder;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RmaItemManagementTest extends TestCase
{
    private ItemRepositoryInterface&MockObject $itemRepository;
    private RmaItemManagement $management;

    protected function setUp(): void
    {
        $rmaRepository = $this->createMock(RMARepositoryInterface::class);
        $rmaRepository->method('get')->willReturn(
            $this->createConfiguredMock(RMAInterface::class, ['getOrderId' => 100])
        );
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->with(100)->willReturn($this->createMock(OrderInterface::class));
        $eligibility = $this->createMock(OrderEligibility::class);
        $eligibility->method('getEligibleItems')->willReturn([['order_item_id' => 5, 'qty_available' => 2]]);
        $this->itemRepository = $this->createMock(ItemRepositoryInterface::class);

        $this->management = new RmaItemManagement(
            $rmaRepository,
            $this->createMock(SearchCriteriaBuilderFactory::class),
            $this->createMock(SortOrderBuilder::class),
            $this->itemRepository,
            $orderRepository,
            $eligibility
        );
    }

    private function item(?int $id, int $orderItemId, int $qty): ItemInterface&MockObject
    {
        return $this->createConfiguredMock(ItemInterface::class, [
            'getEntityId' => $id,
            'getOrderItemId' => $orderItemId,
            'getQtyRequested' => $qty,
        ]);
    }

    public function testNewEligibleItemIsSaved(): void
    {
        $item = $this->item(null, 5, 2);
        $item->expects($this->once())->method('setRmaId')->with(7);
        $this->itemRepository->expects($this->once())->method('save')->willReturn($item);

        $this->management->save(7, $item);
    }

    /**
     * @dataProvider refusedNewItemProvider
     */
    public function testNewItemOutsideTheReturnableQtyIsRefused(int $orderItemId, int $qty): void
    {
        $this->itemRepository->expects($this->never())->method('save');
        $this->expectException(LocalizedException::class);

        $this->management->save(7, $this->item(null, $orderItemId, $qty));
    }

    public static function refusedNewItemProvider(): array
    {
        return [
            'line of another order' => [99, 1],
            'more than returnable' => [5, 3],
            'zero' => [5, 0],
        ];
    }

    public function testItemOfAnotherRmaIsNotReparented(): void
    {
        $this->itemRepository->method('get')->with(40)->willReturn(
            $this->createConfiguredMock(ItemInterface::class, ['getRmaId' => 8, 'getOrderItemId' => 5])
        );
        $this->itemRepository->expects($this->never())->method('save');
        $this->expectExceptionMessageMatches('/does not belong/');

        $this->management->save(7, $this->item(40, 5, 1));
    }
}
