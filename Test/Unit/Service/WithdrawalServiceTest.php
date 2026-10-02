<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Service;

use Magenx\Rma\Api\CommentRepositoryInterface;
use Magenx\Rma\Api\Data\CommentInterface;
use Magenx\Rma\Api\Data\CommentInterfaceFactory;
use Magenx\Rma\Api\Data\ReasonInterface;
use Magenx\Rma\Api\Data\ReasonSearchResultsInterface;
use Magenx\Rma\Api\Data\ResolutionTypeInterface;
use Magenx\Rma\Api\Data\ResolutionTypeSearchResultsInterface;
use Magenx\Rma\Api\Data\RMAInterface;
use Magenx\Rma\Api\Data\RMASearchResultsInterface;
use Magenx\Rma\Api\ReasonRepositoryInterface;
use Magenx\Rma\Api\ResolutionTypeRepositoryInterface;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\Rma\Helper\ModuleConfig;
use Magenx\Rma\Service\OrderEligibility;
use Magenx\Rma\Service\RmaSubmitService;
use Magenx\Rma\Service\WithdrawalEligibility;
use Magenx\Rma\Service\WithdrawalResult;
use Magenx\Rma\Service\WithdrawalService;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderItemInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class WithdrawalServiceTest extends TestCase
{
    private const DECLARED_AT = '2026-10-02 09:00:00';

    private ModuleConfig&MockObject $moduleConfig;
    private OrderEligibility&MockObject $orderEligibility;
    private WithdrawalEligibility&MockObject $withdrawalEligibility;
    private RmaSubmitService&MockObject $rmaSubmitService;
    private RMARepositoryInterface&MockObject $rmaRepository;
    private CommentRepositoryInterface&MockObject $commentRepository;
    private OrderManagementInterface&MockObject $orderManagement;
    private WithdrawalService $service;

    /** @var array<int, array{shipped: int, open: int}> order item id => returnable / open qty */
    private array $lines = [];
    /** @var array<int, int> order item id => qty already on open RMAs */
    private array $requested = [];
    private ?RMAInterface $existingRma = null;
    private bool $enabled = true;

    protected function setUp(): void
    {
        $this->moduleConfig = $this->createMock(ModuleConfig::class);
        $this->moduleConfig->method('isWithdrawalEnabled')->willReturnCallback(fn() => $this->enabled);
        $this->moduleConfig->method('isWithdrawalAutoApproveEnabled')->willReturn(true);

        $this->orderEligibility = $this->createMock(OrderEligibility::class);
        $this->orderEligibility->method('isReturnableType')->willReturn(true);
        $this->orderEligibility->method('getReturnableQty')->willReturnCallback(
            fn(OrderItemInterface $item) => $this->lines[(int)$item->getItemId()]['shipped']
        );
        $this->orderEligibility->method('getEligibleItems')->willReturnCallback(function (): array {
            $eligible = [];
            foreach ($this->lines as $id => $line) {
                $available = $line['shipped'] - ($this->requested[$id] ?? 0);
                if ($available > 0) {
                    $eligible[] = ['order_item_id' => $id, 'qty_available' => $available];
                }
            }
            return $eligible;
        });

        $this->withdrawalEligibility = $this->createMock(WithdrawalEligibility::class);
        $this->rmaSubmitService = $this->createMock(RmaSubmitService::class);

        $this->rmaRepository = $this->createMock(RMARepositoryInterface::class);
        $this->rmaRepository->method('getList')->willReturnCallback(fn() => $this->createConfiguredMock(
            RMASearchResultsInterface::class,
            ['getItems' => $this->existingRma ? [$this->existingRma] : []]
        ));

        $reasonRepository = $this->createMock(ReasonRepositoryInterface::class);
        $reasonRepository->method('getList')->willReturn($this->createConfiguredMock(
            ReasonSearchResultsInterface::class,
            ['getItems' => [$this->createConfiguredMock(ReasonInterface::class, ['getEntityId' => 9])]]
        ));
        $resolutionRepository = $this->createMock(ResolutionTypeRepositoryInterface::class);
        $resolutionRepository->method('getList')->willReturn($this->createConfiguredMock(
            ResolutionTypeSearchResultsInterface::class,
            ['getItems' => [$this->createConfiguredMock(ResolutionTypeInterface::class, ['getEntityId' => 4])]]
        ));

        $this->commentRepository = $this->createMock(CommentRepositoryInterface::class);
        $commentFactory = $this->createMock(CommentInterfaceFactory::class);
        $commentFactory->method('create')->willReturnCallback(fn() => $this->createMock(CommentInterface::class));

        $this->orderManagement = $this->createMock(OrderManagementInterface::class);

        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnSelf();
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('create')->willReturn($this->createMock(SearchCriteria::class));
        $builderFactory = $this->createMock(SearchCriteriaBuilderFactory::class);
        $builderFactory->method('create')->willReturn($builder);

        $this->service = new WithdrawalService(
            $this->moduleConfig,
            $this->orderEligibility,
            $this->withdrawalEligibility,
            $this->rmaSubmitService,
            $this->rmaRepository,
            $reasonRepository,
            $resolutionRepository,
            $this->commentRepository,
            $commentFactory,
            $this->orderManagement,
            $builderFactory,
            $this->createMock(LoggerInterface::class)
        );
    }

    /**
     * @param array<int, array{shipped: int, open: int}> $lines
     */
    private function order(array $lines, ?OrderInterface $order = null): OrderInterface&MockObject
    {
        $this->lines = $lines;
        $items = [];
        foreach ($lines as $id => $line) {
            $items[] = $this->createConfiguredMock(OrderItemInterface::class, [
                'getItemId' => $id,
                'getQtyOrdered' => $line['shipped'] + $line['open'],
                'getQtyRefunded' => 0,
                'getQtyCanceled' => 0,
            ]);
        }

        $order ??= $this->createMock(OrderInterface::class);
        $order->method('getEntityId')->willReturn(100);
        $order->method('getStoreId')->willReturn(1);
        $order->method('getCustomerEmail')->willReturn('jane@example.com');
        $order->method('getCustomerFirstname')->willReturn('Jane');
        $order->method('getCustomerLastname')->willReturn('Smith');
        $order->method('getItems')->willReturn($items);

        return $order;
    }

    private function shippedOn(?string $date, bool $late = false): void
    {
        $this->withdrawalEligibility->method('getLastShipmentDate')->willReturn($date);
        $this->withdrawalEligibility->method('isLate')->willReturn($late);
    }

    public function testRequiresATicketCode(): void
    {
        $this->expectException(LocalizedException::class);

        $this->service->submit($this->order([]), [], ' ', self::DECLARED_AT);
    }

    public function testRepeatedTicketReturnsTheExistingRma(): void
    {
        $this->existingRma = $this->createMock(RMAInterface::class);
        $this->rmaSubmitService->expects($this->never())->method('createRma');

        $result = $this->service->submit($this->order([10 => ['shipped' => 1, 'open' => 0]]), [], 'TX-1', self::DECLARED_AT);

        $this->assertTrue($result->duplicate);
        $this->assertSame($this->existingRma, $result->rma);
        $this->assertFalse($result->needsReview());
    }

    public function testDisabledCreatesNothing(): void
    {
        $this->enabled = false;
        $this->rmaSubmitService->expects($this->never())->method('createRma');
        $this->orderManagement->expects($this->never())->method('cancel');

        $result = $this->service->submit($this->order([10 => ['shipped' => 1, 'open' => 0]]), [], 'TX-1', self::DECLARED_AT);

        $this->assertSame([WithdrawalResult::REVIEW_DISABLED], $result->reviewReasons);
        $this->assertNull($result->rma);
    }

    public function testWholeShippedOrderBecomesOneApprovedWithdrawalRma(): void
    {
        $this->shippedOn('2026-09-25 10:00:00');
        $rma = $this->createMock(RMAInterface::class);
        $rma->expects($this->once())->method('setIsWithdrawal')->with(true);
        $rma->expects($this->once())->method('setHelpdeskTicketCode')->with('TX-1');
        $rma->expects($this->once())->method('setWithdrawalDeclaredAt')->with(self::DECLARED_AT);

        $this->rmaSubmitService->expects($this->once())
            ->method('createRma')
            ->willReturnCallback(function (
                OrderInterface $order,
                ?int $customerId,
                string $email,
                string $name,
                int $reasonId,
                int $resolutionId,
                array $selected,
                string $attachments,
                ?string $status,
                ?callable $prepare
            ) use ($rma) {
                $this->assertSame('jane@example.com', $email);
                $this->assertSame('Jane Smith', $name);
                $this->assertSame(9, $reasonId);
                $this->assertSame(4, $resolutionId);
                $this->assertSame('approved', $status);
                $this->assertSame([
                    10 => ['qty_requested' => 2, 'condition_id' => null],
                    11 => ['qty_requested' => 1, 'condition_id' => null],
                ], $selected);
                $prepare($rma);
                return $rma;
            });
        $this->orderManagement->expects($this->never())->method('cancel');
        $this->commentRepository->expects($this->never())->method('save');

        $result = $this->service->submit(
            $this->order([10 => ['shipped' => 2, 'open' => 0], 11 => ['shipped' => 1, 'open' => 0]]),
            [],
            'TX-1',
            self::DECLARED_AT
        );

        $this->assertSame($rma, $result->rma);
        $this->assertSame([10 => 2, 11 => 1], $result->returnQty);
        $this->assertFalse($result->needsReview());
    }

    public function testSelectedItemsOnlyAndAlreadyRequestedQtyIsSkipped(): void
    {
        $this->shippedOn('2026-09-25 10:00:00');
        $this->requested = [10 => 2];
        $this->rmaSubmitService->expects($this->once())->method('createRma')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), [11 => ['qty_requested' => 1, 'condition_id' => null]])
            ->willReturn($this->createMock(RMAInterface::class));

        $result = $this->service->submit(
            $this->order([10 => ['shipped' => 2, 'open' => 0], 11 => ['shipped' => 3, 'open' => 0], 12 => ['shipped' => 1, 'open' => 0]]),
            [10 => 1, 11 => 1],
            'TX-1',
            self::DECLARED_AT
        );

        $this->assertSame([11 => 1], $result->returnQty);
        $this->assertSame([WithdrawalResult::REVIEW_QTY_EXCEEDS], $result->reviewReasons);
    }

    public function testLateDeclarationStartsAsNewRequestWithStaffNote(): void
    {
        $this->shippedOn('2026-08-01 10:00:00', late: true);
        $rma = $this->createConfiguredMock(RMAInterface::class, ['getEntityId' => 55]);
        $this->rmaSubmitService->expects($this->once())->method('createRma')
            ->with(
                $this->anything(), $this->anything(), $this->anything(), $this->anything(),
                $this->anything(), $this->anything(), $this->anything(), $this->anything(), 'new_request'
            )
            ->willReturn($rma);
        $this->commentRepository->expects($this->once())->method('save');

        $result = $this->service->submit($this->order([10 => ['shipped' => 1, 'open' => 0]]), [], 'TX-1', self::DECLARED_AT);

        $this->assertTrue($result->late);
        $this->assertSame([WithdrawalResult::REVIEW_LATE], $result->reviewReasons);
    }

    public function testNothingShippedCancelsTheOrder(): void
    {
        $this->shippedOn(null);
        $this->rmaSubmitService->expects($this->never())->method('createRma');
        $this->orderManagement->expects($this->once())->method('cancel')->with(100)->willReturn(true);

        $result = $this->service->submit(
            $this->order([10 => ['shipped' => 0, 'open' => 2], 11 => ['shipped' => 0, 'open' => 1]]),
            [],
            'TX-1',
            self::DECLARED_AT
        );

        $this->assertTrue($result->orderCanceled);
        $this->assertNull($result->rma);
        $this->assertSame([10 => 2, 11 => 1], $result->unshippedQty);
        $this->assertFalse($result->needsReview());
    }

    public function testPartOfAnUnshippedOrderIsLeftForStaff(): void
    {
        $this->shippedOn(null);
        $this->orderManagement->expects($this->never())->method('cancel');

        $result = $this->service->submit(
            $this->order([10 => ['shipped' => 0, 'open' => 2], 11 => ['shipped' => 0, 'open' => 1]]),
            [10 => 2],
            'TX-1',
            self::DECLARED_AT
        );

        $this->assertFalse($result->orderCanceled);
        $this->assertSame([WithdrawalResult::REVIEW_UNSHIPPED], $result->reviewReasons);
    }

    public function testMixedShipmentReturnsShippedQtyAndFlagsTheRest(): void
    {
        $this->shippedOn('2026-09-25 10:00:00');
        $rma = $this->createConfiguredMock(RMAInterface::class, ['getEntityId' => 55]);
        $this->rmaSubmitService->expects($this->once())->method('createRma')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), $this->anything(), [10 => ['qty_requested' => 1, 'condition_id' => null]])
            ->willReturn($rma);
        $this->orderManagement->expects($this->never())->method('cancel');
        $this->commentRepository->expects($this->once())->method('save');

        $result = $this->service->submit(
            $this->order([10 => ['shipped' => 1, 'open' => 1]]),
            [10 => 2],
            'TX-1',
            self::DECLARED_AT
        );

        $this->assertSame([10 => 1], $result->returnQty);
        $this->assertSame([10 => 1], $result->unshippedQty);
        $this->assertSame([WithdrawalResult::REVIEW_UNSHIPPED], $result->reviewReasons);
    }

    public function testOrderThatCannotBeCanceledIsLeftForStaff(): void
    {
        $this->shippedOn(null);
        $order = $this->getMockBuilder(Order::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'canCancel', 'getEntityId', 'getStoreId', 'getCustomerEmail',
                'getCustomerFirstname', 'getCustomerLastname', 'getItems',
            ])
            ->getMock();
        $order->method('canCancel')->willReturn(false);
        $this->orderManagement->expects($this->never())->method('cancel');

        $result = $this->service->submit(
            $this->order([10 => ['shipped' => 0, 'open' => 1]], $order),
            [],
            'TX-1',
            self::DECLARED_AT
        );

        $this->assertSame([WithdrawalResult::REVIEW_UNSHIPPED], $result->reviewReasons);
    }

    public function testFailedCancelIsReported(): void
    {
        $this->shippedOn(null);
        $this->orderManagement->method('cancel')->willThrowException(new RuntimeException('payment void failed'));

        $result = $this->service->submit($this->order([10 => ['shipped' => 0, 'open' => 1]]), [], 'TX-1', self::DECLARED_AT);

        $this->assertFalse($result->orderCanceled);
        $this->assertSame([WithdrawalResult::REVIEW_CANCEL_FAILED], $result->reviewReasons);
    }

    public function testUnknownItemIsReported(): void
    {
        $this->shippedOn('2026-09-25 10:00:00');
        $this->rmaSubmitService->expects($this->never())->method('createRma');

        $result = $this->service->submit($this->order([10 => ['shipped' => 1, 'open' => 0]]), [99 => 1], 'TX-1', self::DECLARED_AT);

        $this->assertSame(
            [WithdrawalResult::REVIEW_UNKNOWN_ITEM, WithdrawalResult::REVIEW_NOTHING_TO_DO],
            $result->reviewReasons
        );
    }

    public function testRmaFailureDoesNotThrow(): void
    {
        $this->shippedOn('2026-09-25 10:00:00');
        $this->rmaSubmitService->method('createRma')->willThrowException(new LocalizedException(__('boom')));

        $result = $this->service->submit($this->order([10 => ['shipped' => 1, 'open' => 0]]), [], 'TX-1', self::DECLARED_AT);

        $this->assertNull($result->rma);
        $this->assertSame([WithdrawalResult::REVIEW_RMA_FAILED], $result->reviewReasons);
    }

    public function testWithdrawableItemsListEveryLineWithHeldAndUnshippedQty(): void
    {
        $this->requested = [11 => 1];
        $order = $this->order([10 => ['shipped' => 2, 'open' => 1], 11 => ['shipped' => 1, 'open' => 0]]);

        $lines = $this->service->getWithdrawableItems($order);

        $this->assertSame([10, 11], array_keys($lines));
        $this->assertSame(2, $lines[10]['qty_held']);
        $this->assertSame(1, $lines[10]['qty_unshipped']);
        $this->assertSame(0, $lines[11]['qty_held']);
        $this->assertSame(0, $lines[11]['qty_unshipped']);
    }
}
