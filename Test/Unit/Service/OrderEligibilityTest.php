<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: covers shipped/refunded/canceled qty, released RMA statuses and explain().
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Service;

use Magenx\Rma\Helper\ModuleConfig;
use Magenx\Rma\Model\RMA\StatusResolver;
use Magenx\Rma\Model\ResourceModel\Item\Collection as RmaItemCollection;
use Magenx\Rma\Model\ResourceModel\Item\CollectionFactory as RmaItemCollectionFactory;
use Magenx\Rma\Service\OrderEligibility;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Framework\DataObject;
use Magento\Framework\DB\Select;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Model\Order\Item as OrderItem;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OrderEligibilityTest extends TestCase
{
    private ModuleConfig&MockObject $moduleConfig;
    private RmaItemCollectionFactory&MockObject $rmaItemCollectionFactory;
    private StatusResolver&MockObject $statusResolver;

    protected function setUp(): void
    {
        $this->moduleConfig = $this->createMock(ModuleConfig::class);
        $this->rmaItemCollectionFactory = $this->createMock(RmaItemCollectionFactory::class);
        $this->statusResolver = $this->createMock(StatusResolver::class);
    }

    private function createService(array $stubbedMethods = []): OrderEligibility
    {
        if (empty($stubbedMethods)) {
            return new OrderEligibility(
                $this->moduleConfig,
                $this->rmaItemCollectionFactory,
                $this->statusResolver
            );
        }

        return $this->getMockBuilder(OrderEligibility::class)
            ->setConstructorArgs([
                $this->moduleConfig,
                $this->rmaItemCollectionFactory,
                $this->statusResolver,
            ])
            ->onlyMethods($stubbedMethods)
            ->getMock();
    }

    private function createOrder(int $storeId = 1, string $status = 'complete', string $createdAt = ''): OrderInterface&MockObject
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn($storeId);
        $order->method('getStatus')->willReturn($status);
        $order->method('getCreatedAt')->willReturn($createdAt ?: date('Y-m-d H:i:s'));
        $order->method('getEntityId')->willReturn(100);

        return $order;
    }

    // -------------------------------------------------------------------------
    // isOrderEligible
    // -------------------------------------------------------------------------

    public function testIsOrderEligibleReturnsFalseWhenModuleDisabled(): void
    {
        $this->moduleConfig->method('isEnabled')->with(1)->willReturn(false);
        $service = $this->createService(['getEligibleItems']);

        $this->assertFalse($service->isOrderEligible($this->createOrder()));
    }

    public function testIsOrderEligibleReturnsFalseWhenStatusNotAllowed(): void
    {
        $this->moduleConfig->method('isEnabled')->willReturn(true);
        $this->moduleConfig->method('getAllowedOrderStatuses')->willReturn(['complete']);

        $order = $this->createOrder(status: 'pending');
        $service = $this->createService(['getEligibleItems']);

        $this->assertFalse($service->isOrderEligible($order));
    }

    public function testIsOrderEligibleReturnsFalseWhenOutsideReturnPeriod(): void
    {
        $this->moduleConfig->method('isEnabled')->willReturn(true);
        $this->moduleConfig->method('getAllowedOrderStatuses')->willReturn(['complete']);
        $this->moduleConfig->method('getReturnPeriod')->willReturn(30);

        $order = $this->createOrder(status: 'complete', createdAt: '2020-01-01 00:00:00');
        $service = $this->createService(['getEligibleItems']);

        $this->assertFalse($service->isOrderEligible($order));
    }

    public function testIsOrderEligibleReturnsFalseWhenNoEligibleItems(): void
    {
        $this->moduleConfig->method('isEnabled')->willReturn(true);
        $this->moduleConfig->method('getAllowedOrderStatuses')->willReturn(['complete']);
        $this->moduleConfig->method('getReturnPeriod')->willReturn(0);

        $order = $this->createOrder(status: 'complete');

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getEligibleItems']);
        $service->method('getEligibleItems')->with($order)->willReturn([]);

        $this->assertFalse($service->isOrderEligible($order));
    }

    public function testIsOrderEligibleReturnsTrueWhenAllConditionsMet(): void
    {
        $this->moduleConfig->method('isEnabled')->willReturn(true);
        $this->moduleConfig->method('getAllowedOrderStatuses')->willReturn(['complete']);
        $this->moduleConfig->method('getReturnPeriod')->willReturn(0);

        $order = $this->createOrder(status: 'complete');

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getEligibleItems']);
        $service->method('getEligibleItems')->with($order)->willReturn([
            ['order_item_id' => 1, 'name' => 'Product', 'sku' => 'SKU-1', 'qty_ordered' => 2, 'qty_already_requested' => 0, 'qty_available' => 2],
        ]);

        $this->assertTrue($service->isOrderEligible($order));
    }

    public function testIsOrderEligibleReturnsTrueWhenReturnPeriodIsUnlimited(): void
    {
        $this->moduleConfig->method('isEnabled')->willReturn(true);
        $this->moduleConfig->method('getAllowedOrderStatuses')->willReturn(['complete']);
        $this->moduleConfig->method('getReturnPeriod')->willReturn(0);

        $order = $this->createOrder(status: 'complete', createdAt: '2010-01-01 00:00:00');

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getEligibleItems']);
        $service->method('getEligibleItems')->willReturn([['order_item_id' => 1]]);

        $this->assertTrue($service->isOrderEligible($order));
    }

    // -------------------------------------------------------------------------
    // getEligibleItems
    // -------------------------------------------------------------------------

    private function createOrderItem(
        ?int $parentItemId,
        string $productType,
        int $itemId,
        int $qtyOrdered,
        string $name = 'Product',
        string $sku = 'SKU-001',
        ?int $qtyShipped = null,
        int $qtyRefunded = 0,
        int $qtyCanceled = 0
    ): OrderItemInterface&MockObject {
        $item = $this->createMock(OrderItemInterface::class);
        $item->method('getParentItemId')->willReturn($parentItemId);
        $item->method('getProductType')->willReturn($productType);
        $item->method('getItemId')->willReturn($itemId);
        $item->method('getQtyOrdered')->willReturn($qtyOrdered);
        $item->method('getQtyShipped')->willReturn($qtyShipped ?? $qtyOrdered);
        $item->method('getQtyRefunded')->willReturn($qtyRefunded);
        $item->method('getQtyCanceled')->willReturn($qtyCanceled);
        $item->method('getName')->willReturn($name);
        $item->method('getSku')->willReturn($sku);

        return $item;
    }

    public function testGetEligibleItemsSkipsChildItems(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getItems')->willReturn([
            $this->createOrderItem(parentItemId: 5, productType: 'simple', itemId: 10, qtyOrdered: 1),
        ]);

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn([]);

        $this->assertSame([], $service->getEligibleItems($order));
    }

    public function testGetEligibleItemsSkipsVirtualProducts(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getItems')->willReturn([
            $this->createOrderItem(parentItemId: null, productType: 'virtual', itemId: 10, qtyOrdered: 1),
        ]);

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn([]);

        $this->assertSame([], $service->getEligibleItems($order));
    }

    public function testGetEligibleItemsSkipsDownloadableProducts(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getItems')->willReturn([
            $this->createOrderItem(parentItemId: null, productType: 'downloadable', itemId: 10, qtyOrdered: 1),
        ]);

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn([]);

        $this->assertSame([], $service->getEligibleItems($order));
    }

    public function testGetEligibleItemsSkipsItemsWithNoAvailableQty(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getItems')->willReturn([
            $this->createOrderItem(parentItemId: null, productType: 'simple', itemId: 10, qtyOrdered: 2),
        ]);

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn([10 => 2]);

        $this->assertSame([], $service->getEligibleItems($order));
    }

    public function testGetEligibleItemsDeductsAlreadyRequestedQty(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getItems')->willReturn([
            $this->createOrderItem(parentItemId: null, productType: 'simple', itemId: 10, qtyOrdered: 3, name: 'Shirt', sku: 'SHIRT-L'),
        ]);

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn([10 => 1]);

        $result = $service->getEligibleItems($order);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['qty_already_requested']);
        $this->assertSame(2, $result[0]['qty_available']);
    }

    public function testGetEligibleItemsReturnsCorrectStructure(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getItems')->willReturn([
            $this->createOrderItem(parentItemId: null, productType: 'simple', itemId: 10, qtyOrdered: 2, name: 'Blue Hat', sku: 'HAT-BL'),
        ]);

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn([]);

        $result = $service->getEligibleItems($order);

        $this->assertCount(1, $result);
        $this->assertSame([
            'order_item_id' => 10,
            'name' => 'Blue Hat',
            'sku' => 'HAT-BL',
            'qty_ordered' => 2,
            'qty_already_requested' => 0,
            'qty_available' => 2,
        ], $result[0]);
    }

    public function testGetEligibleItemsIncludesConfigurableProducts(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getItems')->willReturn([
            $this->createOrderItem(parentItemId: null, productType: 'configurable', itemId: 20, qtyOrdered: 1, name: 'T-Shirt', sku: 'TSHIRT-M'),
        ]);

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn([]);

        $result = $service->getEligibleItems($order);

        $this->assertCount(1, $result);
        $this->assertSame('configurable', 'configurable');
        $this->assertSame(20, $result[0]['order_item_id']);
    }

    public function testGetEligibleItemsFiltersMultipleItemTypes(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getItems')->willReturn([
            $this->createOrderItem(parentItemId: null, productType: 'simple', itemId: 1, qtyOrdered: 2, name: 'Book', sku: 'BOOK-1'),
            $this->createOrderItem(parentItemId: null, productType: 'virtual', itemId: 2, qtyOrdered: 1),
            $this->createOrderItem(parentItemId: 1, productType: 'simple', itemId: 3, qtyOrdered: 1),
            $this->createOrderItem(parentItemId: null, productType: 'downloadable', itemId: 4, qtyOrdered: 1),
            $this->createOrderItem(parentItemId: null, productType: 'simple', itemId: 5, qtyOrdered: 1, name: 'Pen', sku: 'PEN-1'),
        ]);

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn([]);

        $result = $service->getEligibleItems($order);

        $this->assertCount(2, $result);
        $this->assertSame(1, $result[0]['order_item_id']);
        $this->assertSame(5, $result[1]['order_item_id']);
    }

    // -------------------------------------------------------------------------
    // returnable qty
    // -------------------------------------------------------------------------

    private function eligibleItemsFor(array $orderItems, array $alreadyRequested = []): array
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(1);
        $order->method('getItems')->willReturn($orderItems);

        /** @var OrderEligibility&MockObject $service */
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn($alreadyRequested);

        return $service->getEligibleItems($order);
    }

    public function testUnshippedQtyIsNotReturnable(): void
    {
        $result = $this->eligibleItemsFor([
            $this->createOrderItem(parentItemId: null, productType: 'simple', itemId: 10, qtyOrdered: 3, qtyShipped: 1),
        ]);

        $this->assertCount(1, $result);
        $this->assertSame(1, $result[0]['qty_available']);
    }

    public function testItemWithNothingShippedIsSkipped(): void
    {
        $result = $this->eligibleItemsFor([
            $this->createOrderItem(parentItemId: null, productType: 'simple', itemId: 10, qtyOrdered: 2, qtyShipped: 0),
        ]);

        $this->assertSame([], $result);
    }

    public function testRefundedQtyIsNotReturnable(): void
    {
        $result = $this->eligibleItemsFor([
            $this->createOrderItem(parentItemId: null, productType: 'simple', itemId: 10, qtyOrdered: 3, qtyRefunded: 2),
        ]);

        $this->assertSame(1, $result[0]['qty_available']);
    }

    public function testRefundOfNeverShippedQtyDoesNotReduceShippedQty(): void
    {
        // Ordered 3, one refunded before dispatch, two shipped: both shipped units stay returnable.
        $result = $this->eligibleItemsFor([
            $this->createOrderItem(
                parentItemId: null,
                productType: 'simple',
                itemId: 10,
                qtyOrdered: 3,
                qtyShipped: 2,
                qtyRefunded: 1
            ),
        ]);

        $this->assertSame(2, $result[0]['qty_available']);
    }

    public function testCanceledQtyIsNotReturnable(): void
    {
        $result = $this->eligibleItemsFor([
            $this->createOrderItem(
                parentItemId: null,
                productType: 'simple',
                itemId: 10,
                qtyOrdered: 4,
                qtyShipped: 4,
                qtyCanceled: 1
            ),
        ]);

        $this->assertSame(3, $result[0]['qty_available']);
    }

    public function testAlreadyRequestedQtyIsDeductedFromShippedQty(): void
    {
        $result = $this->eligibleItemsFor(
            [$this->createOrderItem(parentItemId: null, productType: 'simple', itemId: 10, qtyOrdered: 5, qtyShipped: 3)],
            [10 => 2]
        );

        $this->assertSame(1, $result[0]['qty_available']);
        $this->assertSame(5, $result[0]['qty_ordered']);
    }

    public function testBundleShippedSeparatelyCountsCompleteBundlesFromChildren(): void
    {
        $child = fn(int $ordered, int $shipped): OrderItemInterface => $this->createConfiguredMock(
            OrderItemInterface::class,
            ['getQtyOrdered' => $ordered, 'getQtyShipped' => $shipped]
        );

        // Two bundles of (1 x A + 2 x B); A shipped twice, B only three times: one complete bundle.
        $bundle = $this->getMockBuilder(OrderItem::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQtyOrdered', 'getQtyShipped', 'getQtyRefunded', 'getQtyCanceled', 'getChildrenItems'])
            ->getMock();
        $bundle->method('getQtyOrdered')->willReturn(2);
        $bundle->method('getQtyShipped')->willReturn(0);
        $bundle->method('getQtyRefunded')->willReturn(0);
        $bundle->method('getQtyCanceled')->willReturn(0);
        $bundle->method('getChildrenItems')->willReturn([$child(2, 2), $child(4, 3)]);

        $service = $this->createService();

        $this->assertSame(1, $service->getReturnableQty($bundle));
    }

    // -------------------------------------------------------------------------
    // explain
    // -------------------------------------------------------------------------

    private function enableWithStatus(string $status = 'complete', int $period = 0): void
    {
        $this->moduleConfig->method('isEnabled')->willReturn(true);
        $this->moduleConfig->method('getAllowedOrderStatuses')->willReturn([$status]);
        $this->moduleConfig->method('getReturnPeriod')->willReturn($period);
    }

    public function testExplainReportsDisabled(): void
    {
        $this->moduleConfig->method('isEnabled')->willReturn(false);

        $this->assertSame(OrderEligibility::RESULT_DISABLED, $this->createService()->explain($this->createOrder()));
    }

    public function testExplainReportsOrderStatus(): void
    {
        $this->enableWithStatus('complete');

        $this->assertSame(
            OrderEligibility::RESULT_ORDER_STATUS,
            $this->createService()->explain($this->createOrder(status: 'processing'))
        );
    }

    public function testExplainReportsReturnPeriod(): void
    {
        $this->enableWithStatus('complete', 14);
        $order = $this->createOrder(createdAt: date('Y-m-d H:i:s', strtotime('-20 days')));

        $this->assertSame(OrderEligibility::RESULT_RETURN_PERIOD, $this->createService()->explain($order));
    }

    public function testExplainReportsNotShipped(): void
    {
        $this->enableWithStatus();
        $order = $this->createOrder();
        $order->method('getItems')->willReturn([
            $this->createOrderItem(parentItemId: null, productType: 'simple', itemId: 10, qtyOrdered: 1, qtyShipped: 0),
        ]);
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn([]);

        $this->assertSame(OrderEligibility::RESULT_NOT_SHIPPED, $service->explain($order));
    }

    public function testExplainReportsNoItemsWhenEverythingIsAlreadyRequested(): void
    {
        $this->enableWithStatus();
        $order = $this->createOrder();
        $order->method('getItems')->willReturn([
            $this->createOrderItem(parentItemId: null, productType: 'simple', itemId: 10, qtyOrdered: 1),
        ]);
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn([10 => 1]);

        $this->assertSame(OrderEligibility::RESULT_NO_ITEMS, $service->explain($order));
    }

    public function testExplainReportsOk(): void
    {
        $this->enableWithStatus();
        $order = $this->createOrder();
        $order->method('getItems')->willReturn([
            $this->createOrderItem(parentItemId: null, productType: 'simple', itemId: 10, qtyOrdered: 1),
        ]);
        $service = $this->createService(['getAlreadyRequestedQty']);
        $service->method('getAlreadyRequestedQty')->willReturn([]);

        $this->assertSame(OrderEligibility::RESULT_OK, $service->explain($order));
        $this->assertTrue($service->isOrderEligible($order));
    }

    // -------------------------------------------------------------------------
    // getAlreadyRequestedQty
    // -------------------------------------------------------------------------

    private function mockRequestedCollection(array $rows, Select&MockObject $select): void
    {
        $collection = $this->getMockBuilder(RmaItemCollection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getSelect', 'getTable', 'getIterator'])
            ->getMock();
        $collection->method('getSelect')->willReturn($select);
        $collection->method('getTable')->willReturnArgument(0);
        $collection->method('getIterator')->willReturn(
            new \ArrayIterator(array_map(fn(array $row) => new DataObject($row), $rows))
        );
        $this->rmaItemCollectionFactory->method('create')->willReturn($collection);
    }

    public function testAlreadyRequestedQtyExcludesReleasedStatuses(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('join')->willReturnSelf();
        $wheres = [];
        $select->method('where')->willReturnCallback(function (string $cond, $value) use ($select, &$wheres) {
            $wheres[] = [$cond, $value];
            return $select;
        });
        $this->mockRequestedCollection([
            ['order_item_id' => 10, 'qty_requested' => 1],
            ['order_item_id' => 10, 'qty_requested' => 2],
            ['order_item_id' => 11, 'qty_requested' => 1],
        ], $select);

        $this->statusResolver->expects($this->once())
            ->method('getIdsByCodes')
            ->with(['rejected', 'canceled_by_customer', 'refunded'])
            ->willReturn([4, 7, 9]);

        $result = $this->createService()->getAlreadyRequestedQty(100);

        $this->assertSame([10 => 3, 11 => 1], $result);
        $this->assertSame([['rma.order_id = ?', 100], ['rma.status_id NOT IN (?)', [4, 7, 9]]], $wheres);
    }

    public function testAlreadyRequestedQtySkipsStatusFilterWhenNoReleasedStatusExists(): void
    {
        $select = $this->createMock(Select::class);
        $select->method('join')->willReturnSelf();
        $select->expects($this->once())->method('where')->with('rma.order_id = ?', 100)->willReturnSelf();
        $this->mockRequestedCollection([], $select);
        $this->statusResolver->method('getIdsByCodes')->willReturn([]);

        $this->assertSame([], $this->createService()->getAlreadyRequestedQty(100));
    }
}
