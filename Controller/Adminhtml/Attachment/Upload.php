<?php
/**
 * Mage-OS
 * Copyright (c) Mage-OS Association (https://mage-os.org/)
 * SPDX-License-Identifier: MIT
 *
 * Forked from mage-os/module-rma 2.4.1 into Magenx_Rma / Magenx_RmaGraphQl;
 * identifiers renamed, GraphQL surface split into a sibling module.
 */
declare(strict_types=1);

namespace Magenx\Rma\Controller\Adminhtml\Attachment;

use Magenx\Rma\Api\RMARepositoryInterface;
use Magenx\Rma\Controller\Adminhtml\Rma as BaseController;
use Magenx\Rma\Service\AttachmentService;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Exception;

class Upload extends BaseController implements HttpPostActionInterface
{
    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param AttachmentService $attachmentService
     * @param RMARepositoryInterface $rmaRepository
     */
    public function __construct(
        Context $context,
        protected readonly JsonFactory $jsonFactory,
        protected readonly AttachmentService $attachmentService,
        protected readonly RMARepositoryInterface $rmaRepository
    ) {
        parent::__construct($context);
    }

    /**
     * @return ResultInterface
     */
    public function execute(): ResultInterface
    {
        $result = $this->jsonFactory->create();

        try {
            $fileData = $this->attachmentService->uploadToTmp('attachment', $this->resolveStoreId());
            return $result->setData(['success' => true, 'file' => $fileData]);
        } catch (Exception $e) {
            return $result->setData(['success' => false, 'message' => $e->getMessage()]);
        }
    }

    /**
     * Store the limits should be enforced against.
     *
     * The upload widget advertises the limits of the RMA's own store, so the server has
     * to validate against the same scope or a website-level override would be shown but
     * not honoured. The widget posts `rma_id`; without it we fall back to default scope.
     *
     * @return int
     */
    protected function resolveStoreId(): int
    {
        $rmaId = (int)$this->getRequest()->getParam('rma_id');

        if (!$rmaId) {
            return 0;
        }

        try {
            return (int)$this->rmaRepository->get($rmaId)->getStoreId();
        } catch (NoSuchEntityException) {
            return 0;
        }
    }
}
