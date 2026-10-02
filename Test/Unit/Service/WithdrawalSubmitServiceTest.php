<?php
/**
 * Copyright © MagenX. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Service;

use Magenx\Rma\Api\WithdrawalDeclarationRecorderInterface;
use Magenx\Rma\Model\Withdrawal\UnavailableDeclarationRecorder;
use Magenx\Rma\Service\WithdrawalDeclaration;
use Magenx\Rma\Service\WithdrawalRequest;
use Magenx\Rma\Service\WithdrawalSubmitService;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Api\SearchCriteriaBuilderFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderSearchResultInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class WithdrawalSubmitServiceTest extends TestCase
{
    private WithdrawalDeclarationRecorderInterface&MockObject $recorder;
    private OrderRepositoryInterface&MockObject $orderRepository;
    private SearchCriteriaBuilderFactory&MockObject $builderFactory;
    private ?OrderInterface $storedOrder = null;
    /** @var array<string, mixed> */
    private array $filters = [];

    protected function setUp(): void
    {
        $this->recorder = $this->createMock(WithdrawalDeclarationRecorderInterface::class);
        $this->orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $this->orderRepository->method('getList')->willReturnCallback(fn() => $this->createConfiguredMock(
            OrderSearchResultInterface::class,
            ['getItems' => $this->storedOrder ? [$this->storedOrder] : []]
        ));

        $builder = $this->createMock(SearchCriteriaBuilder::class);
        $builder->method('addFilter')->willReturnCallback(function (string $field, $value) use (&$builder) {
            $this->filters[$field] = $value;
            return $builder;
        });
        $builder->method('setPageSize')->willReturnSelf();
        $builder->method('create')->willReturn($this->createMock(SearchCriteria::class));
        $this->builderFactory = $this->createMock(SearchCriteriaBuilderFactory::class);
        $this->builderFactory->method('create')->willReturn($builder);
    }

    private function service(?WithdrawalDeclarationRecorderInterface $recorder = null): WithdrawalSubmitService
    {
        return new WithdrawalSubmitService(
            $recorder ?? $this->recorder,
            $this->orderRepository,
            $this->builderFactory,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function request(string $email = 'jane@example.com', string $orderNumber = '000000042', ?int $customerId = null): WithdrawalRequest
    {
        return new WithdrawalRequest(1, 'Jane', $email, $orderNumber, 'Blue shirt, SKU HAT-1', 'Too small', $customerId);
    }

    private function storedOrder(int $customerId = 0): OrderInterface
    {
        return $this->storedOrder = $this->createConfiguredMock(OrderInterface::class, [
            'getCustomerEmail' => 'Jane@Example.com',
            'getCustomerId' => $customerId ?: null,
        ]);
    }

    public function testUnavailableRecorderRefuses(): void
    {
        $service = $this->service(new UnavailableDeclarationRecorder());

        $this->assertFalse($service->isAvailable(1));
        $this->expectException(LocalizedException::class);
        $service->submit($this->request());
    }

    public function testRecordsWithTheMatchedOrder(): void
    {
        $order = $this->storedOrder();
        $request = $this->request(' JANE@example.com ');
        $declaration = new WithdrawalDeclaration('TX-1', '2026-10-02 09:00:00');
        $this->recorder->method('isAvailable')->willReturn(true);
        $this->recorder->expects($this->once())->method('record')->with($request, $order)->willReturn($declaration);

        $this->assertSame($declaration, $this->service()->submit($request));
        $this->assertSame(['increment_id' => '000000042', 'store_id' => 1], $this->filters);
    }

    public function testRecordsWithoutAnOrderWhenTheEmailDiffers(): void
    {
        $this->storedOrder();
        $request = $this->request('someone@example.com');
        $this->recorder->method('isAvailable')->willReturn(true);
        $this->recorder->expects($this->once())->method('record')->with($request, null)
            ->willReturn(new WithdrawalDeclaration('TX-1', 'now'));

        $this->service()->submit($request);
    }

    public function testRecordsWithoutAnOrderWhenNoneMatches(): void
    {
        $request = $this->request('jane@example.com', 'no-such-order');
        $this->recorder->method('isAvailable')->willReturn(true);
        $this->recorder->expects($this->once())->method('record')->with($request, null)
            ->willReturn(new WithdrawalDeclaration('TX-1', 'now'));

        $this->service()->submit($request);
    }

    public function testLoggedInOwnerMatchesWithoutTheOrderEmail(): void
    {
        $order = $this->storedOrder(7);

        $this->assertSame($order, $this->service()->matchOrder($this->request('other@example.com', '000000042', 7)));
        $this->assertNull($this->service()->matchOrder($this->request('other@example.com', '000000042', 8)));
    }

    public function testEmptyOrderNumberIsNotLookedUp(): void
    {
        $this->orderRepository->expects($this->never())->method('getList');

        $this->assertNull($this->service()->matchOrder($this->request('jane@example.com', '  ')));
    }

    public function testFailedRecordingFailsTheCall(): void
    {
        $this->recorder->method('isAvailable')->willReturn(true);
        $this->recorder->method('record')->willThrowException(new LocalizedException(__('mail down')));

        $this->expectException(LocalizedException::class);
        $this->service()->submit($this->request());
    }
}
