<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: covers the initial status and prepare arguments of createRma().
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Service;

use Magenx\Rma\Api\Data\RMAInterface;
use Magenx\Rma\Api\Data\RMAInterfaceFactory;
use Magenx\Rma\Api\ItemRepositoryInterface;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\Rma\Helper\ModuleConfig;
use Magenx\Rma\Model\Item;
use Magenx\Rma\Model\ItemFactory;
use Magenx\Rma\Model\RMA\StatusCodes;
use Magenx\Rma\Model\RMA\StatusResolver;
use Magenx\Rma\Service\AttachmentService;
use Magenx\Rma\Service\OrderEligibility;
use Magenx\Rma\Service\RmaSubmitService;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface as EventManagerInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class RmaSubmitServiceTest extends TestCase
{
    private RMARepositoryInterface&MockObject $rmaRepository;
    private RMAInterfaceFactory&MockObject $rmaFactory;
    private ItemFactory&MockObject $itemFactory;
    private ItemRepositoryInterface&MockObject $itemRepository;
    private StatusResolver&MockObject $statusResolver;
    private ModuleConfig&MockObject $moduleConfig;
    private AttachmentService&MockObject $attachmentService;
    private OrderEligibility&MockObject $orderEligibility;
    private ResourceConnection&MockObject $resourceConnection;
    private AdapterInterface&MockObject $connection;
    private EventManagerInterface&MockObject $eventManager;
    private RmaSubmitService $service;

    protected function setUp(): void
    {
        $this->rmaRepository = $this->createMock(RMARepositoryInterface::class);
        $this->rmaFactory = $this->createMock(RMAInterfaceFactory::class);
        $this->itemFactory = $this->createMock(ItemFactory::class);
        $this->itemRepository = $this->createMock(ItemRepositoryInterface::class);
        $this->statusResolver = $this->createMock(StatusResolver::class);
        $this->moduleConfig = $this->createMock(ModuleConfig::class);
        $this->attachmentService = $this->createMock(AttachmentService::class);
        $this->orderEligibility = $this->createMock(OrderEligibility::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->eventManager = $this->createMock(EventManagerInterface::class);

        $this->resourceConnection->method('getConnection')->willReturn($this->connection);

        $this->service = new RmaSubmitService(
            $this->rmaRepository,
            $this->rmaFactory,
            $this->itemFactory,
            $this->itemRepository,
            $this->statusResolver,
            $this->moduleConfig,
            $this->attachmentService,
            $this->orderEligibility,
            $this->resourceConnection,
            $this->eventManager
        );
    }

    // -------------------------------------------------------------------------
    // getSelectedItems
    // -------------------------------------------------------------------------

    public function testGetSelectedItemsFiltersOutUnselectedItems(): void
    {
        $itemsData = [
            10 => ['selected' => '', 'qty_requested' => 1],
            20 => ['selected' => '1', 'qty_requested' => 2],
        ];

        $result = $this->service->getSelectedItems($itemsData);

        $this->assertArrayNotHasKey(10, $result);
        $this->assertArrayHasKey(20, $result);
    }

    public function testGetSelectedItemsFiltersOutZeroQty(): void
    {
        $itemsData = [
            10 => ['selected' => '1', 'qty_requested' => 0],
            20 => ['selected' => '1', 'qty_requested' => 2],
        ];

        $result = $this->service->getSelectedItems($itemsData);

        $this->assertArrayNotHasKey(10, $result);
        $this->assertArrayHasKey(20, $result);
    }

    public function testGetSelectedItemsFiltersOutNegativeQty(): void
    {
        $itemsData = [
            10 => ['selected' => '1', 'qty_requested' => -1],
        ];

        $result = $this->service->getSelectedItems($itemsData);

        $this->assertSame([], $result);
    }

    public function testGetSelectedItemsReturnsCorrectStructure(): void
    {
        $itemsData = [
            10 => ['selected' => '1', 'qty_requested' => 2, 'condition_id' => 3],
            20 => ['selected' => '1', 'qty_requested' => 1],
        ];

        $result = $this->service->getSelectedItems($itemsData);

        $this->assertSame(['qty_requested' => 2, 'condition_id' => 3], $result[10]);
        $this->assertSame(['qty_requested' => 1, 'condition_id' => null], $result[20]);
    }

    public function testGetSelectedItemsReturnsEmptyArrayWhenNoItemsSelected(): void
    {
        $result = $this->service->getSelectedItems([]);

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // createRma
    // -------------------------------------------------------------------------

    public function testCreateRmaThrowsWhenReasonIdIsMissing(): void
    {
        $this->expectException(LocalizedException::class);

        $this->service->createRma(
            order: $this->createMock(OrderInterface::class),
            customerId: 1,
            customerEmail: 'test@example.com',
            customerName: 'Test User',
            reasonId: 0,
            resolutionTypeId: 1,
            selectedItems: []
        );
    }

    public function testCreateRmaThrowsWhenResolutionTypeIdIsMissing(): void
    {
        $this->expectException(LocalizedException::class);

        $this->service->createRma(
            order: $this->createMock(OrderInterface::class),
            customerId: 1,
            customerEmail: 'test@example.com',
            customerName: 'Test User',
            reasonId: 1,
            resolutionTypeId: 0,
            selectedItems: []
        );
    }

    public function testCreateRmaThrowsWhenStatusNotFound(): void
    {
        $this->expectException(LocalizedException::class);

        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn(1);

        $this->moduleConfig->method('isAutoApproveEnabled')->willReturn(false);

        $this->statusResolver->method('getIdByCode')->willReturn(null);

        $this->service->createRma(
            order: $order,
            customerId: 1,
            customerEmail: 'test@example.com',
            customerName: 'Test User',
            reasonId: 1,
            resolutionTypeId: 1,
            selectedItems: []
        );
    }

    public function testCreateRmaSetsNewRequestStatusWhenAutoApproveDisabled(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getEntityId')->willReturn(100);

        $this->moduleConfig->method('isAutoApproveEnabled')->with(1)->willReturn(false);

        $this->statusResolver->expects($this->once())
            ->method('getIdByCode')
            ->with(StatusCodes::NEW_REQUEST)
            ->willReturn(1);

        $rma = $this->createMock(RMAInterface::class);
        $rma->method('getEntityId')->willReturn(10);
        $this->rmaFactory->method('create')->willReturn($rma);

        $this->orderEligibility->method('getEligibleItems')->willReturn([]);
        $this->attachmentService->method('saveFromJson');

        $this->service->createRma(
            order: $order,
            customerId: 1,
            customerEmail: 'test@example.com',
            customerName: 'Test User',
            reasonId: 1,
            resolutionTypeId: 1,
            selectedItems: []
        );
    }

    public function testCreateRmaSetsApprovedStatusWhenAutoApproveEnabled(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getEntityId')->willReturn(100);

        $this->moduleConfig->method('isAutoApproveEnabled')->with(1)->willReturn(true);

        $this->statusResolver->expects($this->once())
            ->method('getIdByCode')
            ->with(StatusCodes::APPROVED)
            ->willReturn(2);

        $rma = $this->createMock(RMAInterface::class);
        $rma->method('getEntityId')->willReturn(10);
        $this->rmaFactory->method('create')->willReturn($rma);

        $this->orderEligibility->method('getEligibleItems')->willReturn([]);
        $this->attachmentService->method('saveFromJson');

        $this->service->createRma(
            order: $order,
            customerId: 1,
            customerEmail: 'test@example.com',
            customerName: 'Test User',
            reasonId: 1,
            resolutionTypeId: 1,
            selectedItems: []
        );
    }

    public function testCreateRmaInitialStatusOverridesAutoApprove(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getEntityId')->willReturn(100);

        $this->moduleConfig->expects($this->never())->method('isAutoApproveEnabled');
        $this->statusResolver->expects($this->once())
            ->method('getIdByCode')
            ->with(StatusCodes::NEW_REQUEST)
            ->willReturn(1);

        $rma = $this->createMock(RMAInterface::class);
        $rma->method('getEntityId')->willReturn(10);
        $this->rmaFactory->method('create')->willReturn($rma);
        $this->orderEligibility->method('getEligibleItems')->willReturn([]);

        $this->service->createRma(
            order: $order,
            customerId: 1,
            customerEmail: 'test@example.com',
            customerName: 'Test User',
            reasonId: 1,
            resolutionTypeId: 1,
            selectedItems: [],
            initialStatusCode: StatusCodes::NEW_REQUEST
        );
    }

    public function testCreateRmaRunsPrepareBeforeSaveAndCommitEvent(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getEntityId')->willReturn(100);

        $this->moduleConfig->method('isAutoApproveEnabled')->willReturn(false);
        $this->statusResolver->method('getIdByCode')->willReturn(1);

        $rma = $this->createMock(RMAInterface::class);
        $rma->method('getEntityId')->willReturn(10);
        $this->rmaFactory->method('create')->willReturn($rma);
        $this->orderEligibility->method('getEligibleItems')->willReturn([]);

        $calls = [];
        $rma->method('setHelpdeskTicketCode')->willReturnCallback(function () use (&$calls, $rma) {
            $calls[] = 'prepare';
            return $rma;
        });
        $this->rmaRepository->method('save')->willReturnCallback(function () use (&$calls, $rma) {
            $calls[] = 'save';
            return $rma;
        });
        $this->eventManager->method('dispatch')->willReturnCallback(function (string $event) use (&$calls) {
            $calls[] = $event;
        });

        $this->service->createRma(
            order: $order,
            customerId: 1,
            customerEmail: 'test@example.com',
            customerName: 'Test User',
            reasonId: 1,
            resolutionTypeId: 1,
            selectedItems: [],
            prepare: fn(RMAInterface $created) => $created->setHelpdeskTicketCode('TX-1')
        );

        $this->assertSame(['prepare', 'save', 'rma_commit_after'], $calls);
    }

    public function testCreateRmaRollsBackTransactionOnException(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getEntityId')->willReturn(100);

        $this->moduleConfig->method('isAutoApproveEnabled')->willReturn(false);

        $this->statusResolver->method('getIdByCode')->willReturn(1);

        $rma = $this->createMock(RMAInterface::class);
        $rma->method('getEntityId')->willReturn(10);
        $this->rmaFactory->method('create')->willReturn($rma);

        $this->rmaRepository->method('save')->willThrowException(new \RuntimeException('DB error'));

        $this->connection->expects($this->once())->method('beginTransaction');
        $this->connection->expects($this->once())->method('rollBack');
        $this->connection->expects($this->never())->method('commit');

        $this->expectException(\RuntimeException::class);

        $this->service->createRma(
            order: $order,
            customerId: 1,
            customerEmail: 'test@example.com',
            customerName: 'Test User',
            reasonId: 1,
            resolutionTypeId: 1,
            selectedItems: []
        );
    }

    // -------------------------------------------------------------------------
    // saveItems
    // -------------------------------------------------------------------------

    public function testSaveItemsThrowsWhenItemNotEligible(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/not eligible/');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('000000001');
        $order->method('getEntityId')->willReturn(100);

        $this->orderEligibility->method('getEligibleItems')->with($order)->willReturn([
            ['order_item_id' => 5, 'qty_available' => 2],
        ]);

        $this->service->saveItems(
            rmaId: 1,
            selectedItems: [99 => ['qty_requested' => 1, 'condition_id' => null]],
            order: $order
        );
    }

    public function testSaveItemsThrowsWhenQtyExceedsAvailable(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessageMatches('/exceeds available/');

        $order = $this->createMock(OrderInterface::class);
        $order->method('getIncrementId')->willReturn('000000001');
        $order->method('getEntityId')->willReturn(100);

        $this->orderEligibility->method('getEligibleItems')->with($order)->willReturn([
            ['order_item_id' => 5, 'qty_available' => 1],
        ]);

        $this->service->saveItems(
            rmaId: 1,
            selectedItems: [5 => ['qty_requested' => 3, 'condition_id' => null]],
            order: $order
        );
    }

    public function testSaveItemsPersistsEachEligibleItem(): void
    {
        $order = $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(100);

        $this->orderEligibility->method('getEligibleItems')->willReturn([
            ['order_item_id' => 1, 'qty_available' => 2],
            ['order_item_id' => 2, 'qty_available' => 1],
        ]);

        $item = $this->createMock(Item::class);
        $this->itemFactory->method('create')->willReturn($item);
        $this->itemRepository->expects($this->exactly(2))->method('save');

        $this->service->saveItems(
            rmaId: 10,
            selectedItems: [
                1 => ['qty_requested' => 1, 'condition_id' => null],
                2 => ['qty_requested' => 1, 'condition_id' => 3],
            ],
            order: $order
        );
    }
}
