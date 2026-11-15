<?php
/**
 * Copyright © Magenx. All rights reserved.
 * SPDX-License-Identifier: MIT
 */
declare(strict_types=1);

namespace Magenx\Rma\Test\Unit\Controller\Adminhtml\Rma;

use Magenx\Rma\Api\Data\RMAInterface;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\Rma\Controller\Adminhtml\Rma\Save;
use Magenx\Rma\Model\RMA\StatusResolver;
use Magenx\Rma\Service\OrderEligibility;
use Magenx\Rma\Service\RmaSubmitService;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\View\Result\Redirect;
use Magento\Backend\Model\View\Result\RedirectFactory;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Api\OrderRepositoryInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SaveTest extends TestCase
{
    private Http&MockObject $request;
    private Redirect&MockObject $redirect;
    private ManagerInterface&MockObject $messageManager;
    private RMARepositoryInterface&MockObject $rmaRepository;
    private RmaSubmitService&MockObject $submitService;
    private OrderEligibility&MockObject $eligibility;
    private Save $controller;

    protected function setUp(): void
    {
        $this->request = $this->createMock(Http::class);
        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setPath')->willReturnSelf();
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($this->redirect);
        $this->messageManager = $this->createMock(ManagerInterface::class);

        $context = $this->createMock(Context::class);
        $context->method('getRequest')->willReturn($this->request);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($this->messageManager);

        $order = $this->createMock(Order::class);
        $order->method('getEntityId')->willReturn(100);
        $order->method('getCustomerEmail')->willReturn('jane@example.com');
        $order->method('getCustomerName')->willReturn('Jane Smith');
        $orderRepository = $this->createMock(OrderRepositoryInterface::class);
        $orderRepository->method('get')->willReturn($order);

        $this->rmaRepository = $this->createMock(RMARepositoryInterface::class);
        $this->submitService = $this->createMock(RmaSubmitService::class);
        $this->submitService->method('getSelectedItems')->willReturn([5 => ['qty_requested' => 1, 'condition_id' => null]]);
        $statusResolver = $this->createMock(StatusResolver::class);
        $statusResolver->method('getCodeById')->with(2)->willReturn('approved');
        $this->eligibility = $this->createMock(OrderEligibility::class);

        $this->controller = new Save(
            $context,
            $this->rmaRepository,
            $orderRepository,
            $this->createMock(DataPersistorInterface::class),
            $this->submitService,
            $statusResolver,
            $this->eligibility
        );

        $this->request->method('getPostValue')->willReturn([
            'order_id' => 100,
            'status_id' => 2,
            'reason_id' => 3,
            'resolution_type_id' => 4,
            'items' => [5 => ['selected' => 1, 'qty_requested' => 1]],
        ]);
    }

    public function testNewRmaIsCreatedInOneTransactionWithTheChosenStatus(): void
    {
        $this->eligibility->method('explain')->willReturn(OrderEligibility::RESULT_OK);
        $rma = $this->createMock(RMAInterface::class);
        $this->submitService->expects($this->once())->method('createRma')
            ->with(
                $this->anything(),
                null,
                'jane@example.com',
                'Jane Smith',
                3,
                4,
                [5 => ['qty_requested' => 1, 'condition_id' => null]],
                '',
                'approved'
            )
            ->willReturn($rma);
        $this->submitService->expects($this->never())->method('saveItems');
        $this->rmaRepository->expects($this->never())->method('save');
        $this->messageManager->expects($this->once())->method('addSuccessMessage');

        $this->controller->execute();
    }

    public function testFailedItemLeavesNothingAndReturnsToTheNewForm(): void
    {
        $this->eligibility->method('explain')->willReturn(OrderEligibility::RESULT_OK);
        $this->submitService->method('createRma')
            ->willThrowException(new LocalizedException(__('Requested qty for item 5 exceeds available qty (0).')));
        $this->rmaRepository->expects($this->never())->method('save');
        $this->messageManager->expects($this->once())->method('addErrorMessage');
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/new', []);

        $this->controller->execute();
    }

    public function testIneligibleOrderIsRefusedWithTheSpecificReason(): void
    {
        $this->eligibility->method('explain')->willReturn(OrderEligibility::RESULT_RETURN_PERIOD);
        $this->eligibility->method('describe')->with(OrderEligibility::RESULT_RETURN_PERIOD)
            ->willReturn(__('The return period for this order has ended.'));
        $this->submitService->expects($this->never())->method('createRma');
        $this->messageManager->expects($this->once())->method('addErrorMessage')
            ->with(__('The return period for this order has ended.'));
        $this->redirect->expects($this->once())->method('setPath')->with('*/*/new');

        $this->controller->execute();
    }
}
