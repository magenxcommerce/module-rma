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
use Magenx\Rma\Service\WithdrawalResult;
use Magenx\Rma\Service\WithdrawalService;
use Magenx\Rma\Service\WithdrawalSubmitService;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

class WithdrawalSubmitServiceTest extends TestCase
{
    private WithdrawalDeclarationRecorderInterface&MockObject $recorder;
    private WithdrawalService&MockObject $withdrawalService;
    private LoggerInterface&MockObject $logger;
    private WithdrawalSubmitService $service;
    private OrderInterface $order;

    protected function setUp(): void
    {
        $this->recorder = $this->createMock(WithdrawalDeclarationRecorderInterface::class);
        $this->withdrawalService = $this->createMock(WithdrawalService::class);
        $this->withdrawalService->method('getWithdrawableItems')->willReturn([
            10 => ['order_item_id' => 10, 'name' => 'Shirt', 'sku' => 'SH-1', 'qty_held' => 1, 'qty_unshipped' => 0],
        ]);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->service = new WithdrawalSubmitService($this->recorder, $this->withdrawalService, $this->logger);
        $this->order = $this->createConfiguredMock(OrderInterface::class, ['getStoreId' => 1, 'getEntityId' => 100]);
    }

    public function testUnavailableRecorderStopsBeforeAnythingHappens(): void
    {
        $service = new WithdrawalSubmitService(
            new UnavailableDeclarationRecorder(),
            $this->withdrawalService,
            $this->logger
        );
        $this->withdrawalService->expects($this->never())->method('submit');

        $this->assertFalse($service->isAvailable(1));
        $this->expectException(LocalizedException::class);
        $service->submit($this->order, 'Jane', 'jane@example.com', []);
    }

    public function testRecordsFirstThenHandsTheTicketToTheReturnStep(): void
    {
        $this->recorder->method('isAvailable')->willReturn(true);
        $this->recorder->expects($this->once())
            ->method('record')
            ->with(
                $this->order,
                'Jane',
                'jane@example.com',
                [
                    10 => ['name' => 'Shirt', 'sku' => 'SH-1', 'qty' => 1],
                    99 => ['name' => 'Order item 99', 'sku' => '', 'qty' => 2],
                ],
                'Too small'
            )
            ->willReturn(new WithdrawalDeclaration('TX-1', '2026-10-02 09:00:00'));
        $result = new WithdrawalResult(null, false, false, [], [10 => 1], []);
        $this->withdrawalService->expects($this->once())
            ->method('submit')
            ->with($this->order, [10 => 1, 99 => 2], 'TX-1', '2026-10-02 09:00:00')
            ->willReturn($result);

        $submission = $this->service->submit($this->order, 'Jane', 'jane@example.com', [10 => 1, 99 => 2], 'Too small');

        $this->assertSame('TX-1', $submission->declaration->ticketCode);
        $this->assertSame($result, $submission->result);
    }

    public function testFailedRecordingFailsTheCall(): void
    {
        $this->recorder->method('isAvailable')->willReturn(true);
        $this->recorder->method('record')->willThrowException(new LocalizedException(__('mail down')));
        $this->withdrawalService->expects($this->never())->method('submit');

        $this->expectException(LocalizedException::class);
        $this->service->submit($this->order, 'Jane', 'jane@example.com', []);
    }

    public function testReturnStepFailureKeepsTheDeclaration(): void
    {
        $this->recorder->method('isAvailable')->willReturn(true);
        $this->recorder->method('record')->willReturn(new WithdrawalDeclaration('TX-1', '2026-10-02 09:00:00'));
        $this->withdrawalService->method('submit')->willThrowException(new RuntimeException('db gone'));
        $this->logger->expects($this->once())->method('critical');

        $submission = $this->service->submit($this->order, 'Jane', 'jane@example.com', []);

        $this->assertSame('TX-1', $submission->declaration->ticketCode);
        $this->assertNull($submission->result);
    }
}
