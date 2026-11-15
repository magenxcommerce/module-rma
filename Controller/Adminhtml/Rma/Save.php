<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 * Modified by MagenX: a new RMA is created through RmaSubmitService::createRma(),
 * so header and items share one transaction and the new-return emails are sent.
 */
declare(strict_types=1);

namespace Magenx\Rma\Controller\Adminhtml\Rma;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magenx\Rma\Api\Data\RMAInterface;
use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\Rma\Controller\Adminhtml\Rma as BaseController;
use Magenx\Rma\Model\RMA\StatusResolver;
use Magenx\Rma\Service\RmaSubmitService;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Request\DataPersistorInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\OrderRepositoryInterface;
use Exception;

class Save extends BaseController implements HttpPostActionInterface
{
    /**
     * @param Context $context
     * @param RMARepositoryInterface $rmaRepository
     * @param OrderRepositoryInterface $orderRepository
     * @param DataPersistorInterface $dataPersistor
     * @param RmaSubmitService $rmaSubmitService
     * @param StatusResolver $statusResolver
     */
    public function __construct(
        Context $context,
        protected readonly RMARepositoryInterface $rmaRepository,
        protected readonly OrderRepositoryInterface $orderRepository,
        protected readonly DataPersistorInterface $dataPersistor,
        protected readonly RmaSubmitService $rmaSubmitService,
        protected readonly StatusResolver $statusResolver
    ) {
        parent::__construct($context);
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $data = $this->getRequest()->getPostValue();

        if (!$data) {
            return $resultRedirect->setPath('*/*/');
        }

        $id = (int)($data['entity_id'] ?? 0);

        return $id
            ? $this->processExistingRma($resultRedirect, $data, $id)
            : $this->processNewRma($resultRedirect, $data);
    }

    /**
     * @param Redirect $resultRedirect
     * @param array $data
     * @param int $id
     * @return ResultInterface
     */
    protected function processExistingRma(Redirect $resultRedirect, array $data, int $id): ResultInterface
    {
        try {
            $model = $this->rmaRepository->get($id);
        } catch (LocalizedException) {
            $this->messageManager->addErrorMessage(__('This RMA request no longer exists.'));

            return $resultRedirect->setPath('*/*/');
        }

        if (isset($data['status_id'])) {
            $model->setStatusId((int)$data['status_id']);
        }

        return $this->saveAndRedirect($resultRedirect, fn(): RMAInterface => $this->rmaRepository->save($model), $data, $id);
    }

    /**
     * @param Redirect $resultRedirect
     * @param array $data
     * @return ResultInterface
     */
    protected function processNewRma(Redirect $resultRedirect, array $data): ResultInterface
    {
        $orderId = (int)($data['order_id'] ?? 0);

        if (!$orderId) {
            $this->messageManager->addErrorMessage(__('Please select an order.'));
            $this->dataPersistor->set('rma_entity', $data);

            return $resultRedirect->setPath('*/*/new');
        }

        $statusId = (int)($data['status_id'] ?? 0);
        $reasonId = (int)($data['reason_id'] ?? 0);
        $resolutionTypeId = (int)($data['resolution_type_id'] ?? 0);

        if (!$statusId || !$reasonId || !$resolutionTypeId) {
            $this->messageManager->addErrorMessage(__('Invalid request.'));
            $this->dataPersistor->set('rma_entity', $data);

            return $resultRedirect->setPath('*/*/new');
        }

        $selectedItems = $this->rmaSubmitService->getSelectedItems($data['items'] ?? []);

        if (empty($selectedItems)) {
            $this->messageManager->addErrorMessage(__('Please select at least one item to return.'));
            $this->dataPersistor->set('rma_entity', $data);

            return $resultRedirect->setPath('*/*/new');
        }

        try {
            $order = $this->orderRepository->get($orderId);
        } catch (LocalizedException) {
            $this->messageManager->addErrorMessage(__('The selected order does not exist.'));
            $this->dataPersistor->set('rma_entity', $data);

            return $resultRedirect->setPath('*/*/new');
        }

        $statusCode = $this->statusResolver->getCodeById($statusId);

        if ($statusCode === null) {
            $this->messageManager->addErrorMessage(__('Invalid request.'));
            $this->dataPersistor->set('rma_entity', $data);

            return $resultRedirect->setPath('*/*/new');
        }

        // One transaction for header and items, and the same lock, checks and
        // new-return emails as a storefront return (PRD F2, FR-S6, NFR-I1).
        return $this->saveAndRedirect(
            $resultRedirect,
            fn(): RMAInterface => $this->rmaSubmitService->createRma(
                $order,
                $order->getCustomerId() ? (int)$order->getCustomerId() : null,
                (string)$order->getCustomerEmail(),
                (string)($order->getCustomerName() ?: __('Guest')),
                $reasonId,
                $resolutionTypeId,
                $selectedItems,
                '',
                $statusCode
            ),
            $data,
            0
        );
    }

    /**
     * @param Redirect $resultRedirect
     * @param callable(): RMAInterface $save Persists the RMA and returns it
     * @param array $data
     * @param int $id
     * @return ResultInterface
     */
    protected function saveAndRedirect(
        Redirect $resultRedirect,
        callable $save,
        array $data,
        int $id
    ): ResultInterface {
        try {
            $model = $save();

            $this->messageManager->addSuccessMessage(__('You saved the RMA request.'));
            $this->dataPersistor->clear('rma_entity');

            if ($this->getRequest()->getParam('back') === 'continue') {
                return $resultRedirect->setPath('*/*/edit', ['entity_id' => $model->getEntityId()]);
            }

            return $resultRedirect->setPath('*/*/');
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        } catch (Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('Something went wrong while saving the RMA request.'));
        }

        $this->dataPersistor->set('rma_entity', $data);

        return $resultRedirect->setPath($id ? '*/*/edit' : '*/*/new', $id ? ['entity_id' => $id] : []);
    }
}
